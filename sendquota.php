<?php
/**
 * sendquota
 *
 * @version 0.1 - 19.03.2012
 * @author Yann Autissier
 * @website http://www.olympe-network.com
 * @licence GNU GPL V3
 *
 **/
 
/**
 * Usage: This plugin implements quota in roundcube for Olympe Network
 * User account is stored in ldap, and quota in mysql
 *
 **/ 
 
class sendquota extends rcube_plugin
{
    public $task = 'mail|settings';
    
    static private $db = null;
    static private $ldb = null;

    static private $userId = null;
    static private $userQuota = array( 'used' => null, 'max' => null );

    private $ldap_get_userlogin             = '(&(mail=%email)(objectclass=posixAccount)(objectclass=inetMailUser))';
    private $sql_select_userid                = "SELECT user_id FROM hosting_user WHERE user_login='%userLogin'";
    private $sql_select_userquota         = "SELECT quota_used, quota_max FROM hosting_quota WHERE quota_user='%userId' and quota_resource = 5";
    private $sql_update_userquota         = "UPDATE hosting_quota SET quota_used=%quota WHERE quota_user='%userId' and quota_resource = 5";

    /* unified plugin properties */
    static private $plugin = 'sendquota';
    static private $author = 'support@olympe-network.com';
    static private $authors_comments = null;
    static private $download = 'http://github.com/anotherservice/sendquota';
    static private $version = '0.1';
    static private $date = '22-03-2012';
    static private $licence = 'GPL';
    static private $requirements = array(
        'Roundcube' => '0.7.1',
        'PHP' => '5.3'
    );
    static private $prefs = null;
    static private $config_dist = 'config.inc.php.dist';

    function init()
    {
        $rcmail = rcmail::get_instance();
        $plugins = $rcmail->config->get('plugins');
        $plugins = array_flip($plugins);
        if( !isset($plugins['global_config']) )
        {
            $this->load_config();
        }
        $this->add_hook( 'message_outgoing_headers', array( $this,'sendquota_check' ) );
    }
    
    static public function about($keys = false)
    {
        $requirements = self::$requirements;
        foreach(array('required_', 'recommended_') as $prefix){
            if(is_array($requirements[$prefix.'plugins'])){
                foreach($requirements[$prefix.'plugins'] as $plugin => $method){
                    if(class_exists($plugin) && method_exists($plugin, 'about')){
                        $requirements[$prefix.'plugins'][$plugin] = array(
                            'method' => $method,
                            'plugin' => $plugin::about($keys),
                        );
                    }
                    else{
                        $requirements[$prefix.'plugins'][$plugin] = array(
                            'method' => $method,
                            'plugin' => $plugin,
                        );
                    }
                }
            }
        }
        $rcmail_config = array();
        if(is_string(self::$config_dist)){
            if(is_file($file = INSTALL_PATH . 'plugins/' . self::$plugin . '/' . self::$config_dist))
                include $file;
            else
                write_log('errors', self::$plugin . ': ' . self::$config_dist . ' is missing!');
        }
        $ret = array(
            'plugin' => self::$plugin,
            'version' => self::$version,
            'date' => self::$date,
            'author' => self::$author,
            'comments' => self::$authors_comments,
            'licence' => self::$licence,
            'download' => self::$download,
            'requirements' => $requirements,
        );
        if(is_array(self::$prefs))
            $ret['config'] = array_merge($rcmail_config, array_flip(self::$prefs));
        else
            $ret['config'] = $rcmail_config;
        if(is_array($keys)){
            $return = array('plugin' => self::$plugin);
            foreach($keys as $key){
                $return[$key] = $ret[$key];
            }
            return $return;
        }
        else{
            return $ret;
        }
    }
    
    function sendquota_check($args)
    {
        $rcmail = rcmail::get_instance();
        if(get_input_value('_draft', RCUBE_INPUT_POST)){
            return $args;
        }

        if( is_array( $rcmail->config->get('no_sendquota') ) && in_array( $rcmail->user->data['username'], $no_quota ) )
        {
            $this->log_debug( 'Quota disabled for user '.$rcmail->user->data['username'] );
            return $args;
        }

        // mysql initialization
        if ( ( $mysql_host = $rcmail->config->get('db_sendquota_host') ) && ( $mysql_user = $rcmail->config->get('db_sendquota_user') ) && ( $mysql_pass = $rcmail->config->get('db_sendquota_pass') ) && ( $mysql_base = $rcmail->config->get('db_sendquota_base') ) )
        {
            $this->db = @mysql_pconnect( $mysql_host, $mysql_user, $mysql_pass );
            if( !$this->db )
            {
                $this->log_error(mysql_error());
                return $args;
            }
            $db = @mysql_select_db( $mysql_base );
            if( !$db )
            {
                $this->log_error(mysql_error());
                return $args;
            }
        }
        else
        {
            $this->log("FATAL ERROR ::: RoundCube Plugin ::: sendquota ::: \$rcmail_config['db_sendquota_host'] or \$rcmail_config['db_sendquota_user']or \$rcmail_config['db_sendquota_pass']    or \$rcmail_config['db_sendquota_base'] not configured !!!");
            return $args;
        }

        // ldap initialization
        if ($ldap_host = $rcmail->config->get('ldap_sendquota_host') )
        {
            if( $rcmail->config->get('ldap_user') != '' )
                $ldap_user = $rcmail->config->get('ldap_user');
            if( $rcmail->config->get('ldap_pass') != '' )
                $ldap_pass = $rcmail->config->get('ldap_pass');
            $this->ldb = @ldap_connect( $ldap_host );
            if( !$this->ldb )
            {
                $this->log_error('Unable to connect to server '.$ldap_host);
                return $args;
            }
            ldap_set_option($this->ldb, LDAP_OPT_PROTOCOL_VERSION, 3);
            $bind = @ldap_bind( $this->ldb, $ldap_user, $ldap_pass );
            if( !$bind )
            {
                $this->log_error(ldap_error($this->ldb));
                return $args;
            }
        }
        else
        {
            $this->log("FATAL ERROR ::: RoundCube Plugin ::: sendquota ::: \$rcmail_config['ldap_host'] not configured !!!");
            return $args;
        }

        // localization initialization
        $this->add_texts('localization/');

        // count mail recipients
        $count = 0;
        $to    = $args['headers']['To'];
        if( $to != '' )
            $count += count( explode( ',', $to ) );
        $cc    = $args['headers']['Cc'];
        if( $cc != '' )
            $count += count( explode( ',', $cc ) );
        $bcc = $args['headers']['Bcc'];
        if( $bcc != '' )
            $count += count( explode( ',', $bcc ) );

        $this->log_debug('username : '.$rcmail->user->data['username']);
        $this->log_debug('to : '.$args['headers']['To']);
        $this->log_debug('cc : '.$args['headers']['Cc']);
        $this->log_debug('bcc : '.$args['headers']['Bcc']);
        $this->log_debug('count : '.$count);

        // search user in ldap
        if( !$this->getUserLogin() )
        {
            $this->log_error('User Unknown : '.$rcmail->user->data['username'].' --> IP : '.$this->getVisitorIP());
            $rcmail->output->command('display_message',sprintf(rcube_label('sendquota_getuserlogin_error','sendquota')),'error');
            $rcmail->output->send('iframe');
        }

        $unbind = @ldap_unbind( $this->ldb );

        // get id of user in mysql
        if( !$this->getUserId() )
        {
            $this->log_error('Unknown User Id : '.$rcmail->user->data['username'].' --> IP : '.$this->getVisitorIP());
            $rcmail->output->command('display_message',sprintf(rcube_label('sendquota_getuserid_error','sendquota')),'error');
            $rcmail->output->send('iframe');
        }
        // get user quota
        if( !$this->getUserQuota() )
        {
            $this->log_error('Unable to get quota for user : '.$rcmail->user->data['username'].' --> IP : '.$this->getVisitorIP());
            $rcmail->output->command('display_message',sprintf(rcube_label('sendquota_getuserquota_error','sendquota')),'error');
            $rcmail->output->send('iframe');
        }
        // check user quota
        if( $this->userQuota['used'] + $count > $this->userQuota['max'] )
        {
            $this->log('Quota Excedeed for user '.$rcmail->user->data['username'].' : '.$this->userQuota['used'].' + '.$count.' > '.$this->userQuota['max'].' --> IP: '.$this->getVisitorIP());
            $rcmail->output->command('display_message',sprintf(rcube_label('sendquota_msg','sendquota'),$this->userQuota['max']),'error');
            $rcmail->output->send('iframe');
        // update user quota
        }
        else
        {
            if( !$this->updateUserQuota( $count ) )
            {
                $this->log_error('Unable to update quota for user : '.$rcmail->user->data['username'].' ( used : '.$this->userQuota['used'].' / count : '.$count.' ) --> IP : '.$this->getVisitorIP());
                $rcmail->output->command('display_message',sprintf(rcube_label('sendquota_updateuserquota_error','sendquota'),$rcmail->config->get('sendquota')),'error');
                $rcmail->output->send('iframe');
            }
        }

        $close = @mysql_close( $this->db );
        $this->log_debug( 'quota updated for user : '.$rcmail->user->data['username'].', IP : '.$this->getVisitorIP() );
        return $args;

    }

    function getUserLogin()
    {
        $rcmail = rcmail::get_instance();
        $filter = str_replace('%email', $rcmail->user->data['username'], $this->ldap_get_userlogin);
        if( $ldap_dn = $rcmail->config->get('ldap_sendquota_dn') )
        {
            $ldap_attributes = array( 'manager' );
            $search = @ldap_search( $this->ldb, $ldap_dn, $filter, $ldap_attributes );
            if( !$search )
            {
                $this->log_debug( $filter );
                $this->log_error( 'ldap_search : '.ldap_error( $this->ldb ) );
                return false;
            }
            $entries = @ldap_get_entries( $this->ldb, $search );
            if( !$entries )
            {
                $this->log_debug( $filter );
                $this->log_error( 'ldap_get_entries : '.ldap_error( $this->ldb ) );
                return false;
            }
            foreach( $entries as $entry )
            {
                if( is_array( $entry['manager'] ) )
                {
                    foreach( $entry['manager'] as $manager)
                    {
                        $account = explode( ',', $manager );
                        if( count( $account ) ==    4 && $account[1] == 'dc=olympe-network' )
                        {
                            $this->userLogin = str_replace( 'dc=', '', $account[0] );
                            $this->log_debug('userLogin : '.$this->userLogin );
                            return true;
                        }
                    }
                }
            }
        }
        else
        {
            $this->log("FATAL ERROR ::: RoundCube Plugin ::: sendquota ::: \$rcmail_config['ldap_dn'] not configured !!!");
            return false;
        }
        $this->log_error( 'getUserLogin returned false' );
        return false;
    }

    function getUserId()
    { 
        $rcmail = rcmail::get_instance();
        $sql = str_replace('%userLogin', $this->userLogin, $this->sql_select_userid );
        $res = @mysql_query( $sql, $this->db );
        if ( !$res )
        {
            $this->log_debug( $sql );
            $this->log_error( mysql_error() );
            return false;
        }
        $ret = @mysql_fetch_row( $res );
        if( is_array( $ret ) )
        {
            $this->userId = $ret[0];
            $this->log_debug('userId : '.$this->userId );
            return true;
        }
        else
        {
            $this->log_debug( $sql );
            $this->log( mysql_error() );
            return false;
        }
        $this->log_error( 'getUserId returned false' );
        return false;
    }

    function getUserQuota()
    { 
        $rcmail = rcmail::get_instance();
        $sql = str_replace('%userId', $this->userId, $this->sql_select_userquota);
        $res = @mysql_query( $sql, $this->db );
        if ( !$res )
        {
            $this->log_debug( $sql );
            $this->log( mysql_error() );
            return false;
        }
        $ret = @mysql_fetch_row( $res );
        if( is_array( $ret ) )
        {
            $this->userQuota['used'] = $ret[0];
            $this->userQuota['max'] = $ret[1];
            $this->log_debug('quota used : '.$this->userQuota['used'] );
            $this->log_debug('quota max : '.$this->userQuota['max'] );
            return true;
        } else {
            $this->log_debug( $sql );
            $this->log( mysql_error() );
            return false;
        }
        $this->log_error( 'getUserQuota returned false' );
        return false;
    }

    function updateUserQuota( $count )
    { 
        $rcmail = rcmail::get_instance();
        $quota = $this->userQuota['used'] + $count;
        $sql = $this->sql_update_userquota;
        $sql = str_replace('%quota', $quota, $sql);
        $sql = str_replace('%userId', $this->userId, $sql);
        $res = @mysql_query( $sql, $this->db );
        if ( !$res )
        {
            $this->log_debug( $sql );
            $this->log( mysql_error() );
            return false;
        }
        else
        {
            $this->log_debug( 'new quota : '.$quota );
            return true;
        }
        $this->log_error( 'updateUserQuota returned false' );
        return false;
    }

    function getVisitorIP() { 
    
        //Regular expression pattern for a valid IP address 
        $ip_regexp = "/^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})/"; 

        //Retrieve IP address from which the user is viewing the current page 
        if (isset ($HTTP_SERVER_VARS["HTTP_X_FORWARDED_FOR"]) && !empty ($HTTP_SERVER_VARS["HTTP_X_FORWARDED_FOR"])) { 
            $visitorIP = (!empty ($HTTP_SERVER_VARS["HTTP_X_FORWARDED_FOR"])) ? $HTTP_SERVER_VARS["HTTP_X_FORWARDED_FOR"] : ((!empty ($HTTP_ENV_VARS['HTTP_X_FORWARDED_FOR'])) ? $HTTP_ENV_VARS['HTTP_X_FORWARDED_FOR'] : @ getenv ('HTTP_X_FORWARDED_FOR')); 
        } 
        else { 
            $visitorIP = (!empty ($HTTP_SERVER_VARS['REMOTE_ADDR'])) ? $HTTP_SERVER_VARS['REMOTE_ADDR'] : ((!empty ($HTTP_ENV_VARS['REMOTE_ADDR'])) ? $HTTP_ENV_VARS['REMOTE_ADDR'] : @ getenv ('REMOTE_ADDR')); 
        } 

        return $visitorIP; 
    }     
    
    function log( $log )
    {
        $rcmail = rcmail::get_instance();
        if( $rcmail->config->get( 'sendquota_log' ) )
        {
            write_log( 'sendquota', $log );
        }    
    }

    function log_error( $log )
    {
        $rcmail = rcmail::get_instance();
        if( $rcmail->config->get( 'sendquota_log_error' ) )
        {
            write_log( 'sendquota', $log );
        }    
    }

    function log_debug( $log )
    {
        $rcmail = rcmail::get_instance();
        if( $rcmail->config->get( 'sendquota_log_debug' ) )
        {
            write_log( 'sendquota', $log );
        }    
    }

}
?>
