<?php

$labels = array();
$labels['sendquota'] = 'Quotas mails';

$messages = array();
$messages['sendquota_msg'] = 'Vous avez depasse votre quota (%s)';
$messages['sendquota_init_ldap_error'] = 'Erreur lors de l\'initialisation du plugin sendquota: connexion ldap impossible';
$messages['sendquota_init_mysql_error'] = 'Erreur lors de l\'initialisation du plugin sendquota: connexion mysql impossible';
$messages['sendquota_getuserlogin_error'] = 'Erreur lors de la recuperation de votre compte';
$messages['sendquota_getuserid_error'] = 'Erreur lors de la recuperation de votre identifiant';
$messages['sendquota_getuserquota_error'] = 'Erreur lors de la recuperation de votre quota';
$messages['sendquota_updateuserquota_error'] = 'Erreur lors de la mise a jour de votre quota';

?>
