<?php
// No direct access
defined('_JEXEC') or die('Restricted access');


class PlgSystemJsupermailer extends JPlugin
{
    /**
     * Here we will override the JMail class
     *
     * @return bool
     */
    public function onAfterInitialise()
    {
        $this->loadLanguage('plg_system_jsupermailer.sys');

        require_once JPATH_LIBRARIES . '/jsupermailer/autoload.php';
        $path = JPATH_ROOT . '/plugins/system/jsupermailer/jsupermailer/mail.php';

        JLoader::register('JMail', $path);
        JLoader::load('JMail');
        return true;
    }
}

