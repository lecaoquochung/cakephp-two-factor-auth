<?php
namespace TwoFactorAuth\Controller;

use Cake\Controller\Controller;
use Cake\Event\Event;

/**
 * TwoFactorAuth Controller
 */
class TwoFactorAuthController extends Controller
{

    /**
     * Called before the controller action. You can use this method to configure and customize components
     * or perform logic that needs to happen before each controller action.
     *
     * @param \Cake\Event\Event $event An Event instance
     * @return \Cake\Network\Response|null
     * @link http://book.cakephp.org/3.0/en/controllers.html#request-life-cycle-callbacks
     */
    public function beforeFilter(Event $event)
    {
        parent::beforeFilter($event);

        $this->Auth->allow(['verify']);
    }

    /**
     *  One-time code verify action
     */
    public function verify()
    {
        $this->set('loginAction', $this->Auth->config('loginAction'));
    }
}
