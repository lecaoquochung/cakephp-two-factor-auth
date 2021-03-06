<?php
namespace TwoFactorAuth\Test\TestCase\Auth;

use Cake\Controller\Component;
use Cake\Controller\ComponentRegistry;
use Cake\Core\Configure;
use Cake\I18n\Time;
use Cake\Network\Request;
use Cake\Network\Response;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use TwoFactorAuth\Auth\FormAuthenticate;
use TwoFactorAuth\Test\App\Controller\AuthTestController;

/**
 * TwoFactorAuth\Controller\Component\FormAuthenticate Test Case
 *
 * @property \TwoFactorAuth\Auth\FormAuthenticate $auth;
 * @property \Cake\Controller\ComponentRegistry $ComponentRegistry;
 * @property \PHPUnit_Framework_MockObject_MockObject|Request $request;
 * @property \PHPUnit_Framework_MockObject_MockObject|Response $response;
 * @property AuthTestController $Controller;
 */
class FormAuthenticateTest extends TestCase
{
    /**
     * @var \TwoFactorAuth\Auth\FormAuthenticate $auth
     */
    private $auth;

    /**
     * Fixtures
     *
     * @var array
     */
    public $fixtures = ['plugin.TwoFactorAuth.users', 'plugin.TwoFactorAuth.auth_users'];

    /**
     * setUp method
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();

        $this->request = new Request();
        $this->response = new Response();

        $this->Controller = new AuthTestController($this->request, $this->response);
        $this->protectedMethodCall($this->Controller->Auth, '_setDefaults');
        $this->ComponentRegistry = new ComponentRegistry($this->Controller);
        $this->auth = new FormAuthenticate($this->ComponentRegistry);

        $password = password_hash('password', PASSWORD_DEFAULT);
        TableRegistry::clear();
        $Users = TableRegistry::get('Users');
        $Users->updateAll(['password' => $password], []);
        $AuthUsers = TableRegistry::get('AuthUsers', [
            'className' => 'TwoFactorAuth\Test\App\Model\Table\AuthUsersTable'
        ]);
        $AuthUsers->updateAll(['password' => $password], []);
    }

    /**
     * test applying settings in the constructor
     *
     * @return void
     */
    public function testConstructor()
    {
        $this->auth->config([
            'userModel' => 'AuthUsers',
            'fields' => ['username' => 'user', 'password' => 'password', 'secret' => 'secret']
        ]);

        $this->assertEquals('AuthUsers', $this->auth->config('userModel'));
        $this->assertEquals(
            ['username' => 'user', 'password' => 'password', 'secret' => 'secret'],
            $this->auth->config('fields')
        );
    }

    /**
     * test getting user credentials from request
     *
     * @return void
     */
    public function testGetCredentialsFromRequest()
    {
        $this->auth->config([
            'userModel' => 'AuthUsers',
            'fields' => ['username' => 'user', 'password' => 'password', 'secret' => 'secret']
        ]);

        $this->request->data = ['user' => 'testUsername', 'password' => 'testPassword'];

        $this->assertEquals(
            ['username' => 'testUsername', 'password' => 'testPassword'],
            $this->protectedMethodCall($this->auth, '_getCredentials', [$this->request])
        );
    }

    /**
     * test getting user credentials from session
     *
     * @return void
     */
    public function testGetCredentialsFromSession()
    {
        $this->auth->config([
            'userModel' => 'AuthUsers',
            'fields' => ['username' => 'user', 'password' => 'password', 'secret' => 'secret']
        ]);

        $this->request->session()->write(['TwoFactorAuth.credentials' => ['username' => 'testUsername', 'password' => 'testPassword']]);

        $this->assertEquals(
            ['username' => 'testUsername', 'password' => 'testPassword'],
            $this->protectedMethodCall($this->auth, '_getCredentials', [$this->request])
        );
    }

    /**
     * test getting user credentials from request priority over session
     *
     * @return void
     */
    public function testGetCredentialsFromRequestOverSession()
    {
        $this->auth->config([
            'userModel' => 'AuthUsers',
            'fields' => ['username' => 'user', 'password' => 'password', 'secret' => 'secret']
        ]);

        $this->request->data = ['user' => 'testUsernameFromRequest', 'password' => 'testPasswordFromRequest'];
        $this->request->session()->write(['TwoFactorAuth.credentials' => ['username' => 'testUsername', 'password' => 'testPassword']]);

        $this->assertEquals(
            ['username' => 'testUsernameFromRequest', 'password' => 'testPasswordFromRequest'],
            $this->protectedMethodCall($this->auth, '_getCredentials', [$this->request])
        );
    }

    /**
     * test getting user credentials when they're not set
     *
     * @return void
     */
    public function testGetCredentialsNone()
    {
        $this->auth->config([
            'userModel' => 'AuthUsers',
            'fields' => ['username' => 'user', 'password' => 'password', 'secret' => 'secret']
        ]);

        $this->assertFalse($this->protectedMethodCall($this->auth, '_getCredentials', [$this->request]));
    }

    /**
     * test authenticating user having a secret, but no one-time code passed
     *
     * @return void
     */
    public function testAuthenticateWithSecretNoCode()
    {
        $credentials = [
            'username' => 'nate',
            'password' => 'password'
        ];

        $this->request->data = $credentials;
        $this->response = $this->getMock('Cake\Network\Response', ['location']);

        $this->Controller->Auth->config('verifyAction', 'testVerifyAction');

        $this->response->expects($this->once())
            ->method('location')
            ->with('/testVerifyAction')
            ->will($this->returnValue(true));

        $this->assertFalse($this->auth->authenticate($this->request, $this->response));

        $this->assertEquals($credentials, $this->request->session()->read('TwoFactorAuth.credentials'));
    }

    /**
     * test authenticating user having a secret, invalid code passed
     *
     * @return void
     */
    public function testAuthenticateWithSecretCodeInvalid()
    {
        $this->request->data = ['code' => '123'];
        $this->request->session()->write([
            'TwoFactorAuth' => [
                'credentials' => [
                    'username' => 'nate',
                    'password' => 'password'
                ]
            ]
        ]);
        $this->response = $this->getMock('Cake\Network\Response', ['location']);

        $this->Controller->Auth->config('verifyAction', 'testVerifyAction');

        $this->response->expects($this->once())
            ->method('location')
            ->with('/testVerifyAction')
            ->will($this->returnValue(true));

        $this->assertFalse($this->auth->authenticate($this->request, $this->response));
        $this->assertEquals(
            'Invalid two-step verification code.',
            $this->request->session()->read('Flash.auth.0.message')
        );
    }

    /**
     * test authenticating user having a secret, credentials in session, but no one-time code passed
     *
     * @return void
     */
    public function testAuthenticateWithSecretCodeNone()
    {
        $this->request->session()->write([
            'TwoFactorAuth' => [
                'credentials' => [
                    'username' => 'nate',
                    'password' => 'password'
                ]
            ]
        ]);
        $this->response = $this->getMock('Cake\Network\Response', ['location']);

        $this->Controller->Auth->config('verifyAction', 'testVerifyAction');

        $this->response->expects($this->once())
            ->method('location')
            ->with('/testVerifyAction')
            ->will($this->returnValue(true));

        $this->assertFalse($this->auth->authenticate($this->request, $this->response));
    }

    /**
     * test authenticating user having a secret and correct code
     *
     * @return void
     */
    public function testAuthenticateWithSecretSuccess()
    {
        $secret = TableRegistry::get('Users')->find()->where(['username' => 'nate'])->first()->get('secret');

        $this->request->data = ['code' => $this->Controller->Auth->tfa->getCode($secret)];
        $this->request->session()->write([
            'TwoFactorAuth' => [
                'credentials' => [
                    'username' => 'nate',
                    'password' => 'password'
                ]
            ]
        ]);
        $this->response = $this->getMock('Cake\Network\Response', ['location']);

        $this->Controller->Auth->config('verifyAction', 'testVerifyAction');

        $this->response->expects($this->never())
            ->method('location')
            ->will($this->returnValue(true));

        $expected = [
            'id' => 2,
            'username' => 'nate',
            'created' => new Time('2008-03-17 01:18:23'),
            'updated' => new Time('2008-03-17 01:20:31')
        ];

        $this->assertEquals(
            $expected,
            $this->auth->authenticate($this->request, $this->response)
        );

        $this->assertNull($this->request->session()->read('TwoFactorAuth.credentials'));
    }

    /**
     * test authenticating when wrong Auth component used
     *
     * @return void
     * @expectedException \Exception
     * @expectedExceptionMessage TwoFactorAuth.Auth component has to be used for authentication.
     */
    public function testWrongAuthComponentUsed()
    {
        $this->request->data = ['code' => '123'];
        $this->request->session()->write([
            'TwoFactorAuth' => [
                'credentials' => [
                    'username' => 'nate',
                    'password' => 'password'
                ]
            ]
        ]);

        $this->Controller->Auth = new Component\AuthComponent($this->ComponentRegistry);

        $this->assertFalse($this->auth->authenticate($this->request, $this->response));
    }

    /**
     * test the authenticate method
     *
     * @return void
     */
    public function testAuthenticateNoData()
    {
        $request = new Request('posts/index');
        $request->data = [];
        $this->assertFalse($this->auth->authenticate($request, $this->response));
    }

    /**
     * test the authenticate method
     *
     * @return void
     */
    public function testAuthenticateNoUsername()
    {
        $request = new Request('posts/index');
        $request->data = ['password' => 'foobar'];
        $this->assertFalse($this->auth->authenticate($request, $this->response));
    }

    /**
     * test the authenticate method
     *
     * @return void
     */
    public function testAuthenticateNoPassword()
    {
        $request = new Request('posts/index');
        $request->data = ['username' => 'mariano'];
        $this->assertFalse($this->auth->authenticate($request, $this->response));
    }

    /**
     * test authenticate password is false method
     *
     * @return void
     */
    public function testAuthenticatePasswordIsFalse()
    {
        $request = new Request('posts/index', false);
        $request->data = [
            'username' => 'mariano',
            'password' => null
        ];
        $this->assertFalse($this->auth->authenticate($request, $this->response));
    }

    /**
     * Test for password as empty string with _getCredentials() call skipped
     * Refs https://github.com/cakephp/cakephp/pull/2441
     *
     * @return void
     */
    public function testAuthenticatePasswordIsEmptyString()
    {
        $request = new Request('posts/index', false);
        $request->data = [
            'username' => 'mariano',
            'password' => ''
        ];
        $this->auth = $this->getMock(
            'TwoFactorAuth\Auth\FormAuthenticate',
            ['_getCredentials'],
            [
                $this->ComponentRegistry,
                [
                    'userModel' => 'Users'
                ]
            ]
        );
        // Simulate that check for ensuring password is not empty is missing.
        $this->auth->expects($this->once())
            ->method('_getCredentials')
            ->will($this->returnValue(true));
        $this->assertFalse($this->auth->authenticate($request, $this->response));
    }

    /**
     * test authenticate field is not string
     *
     * @return void
     */
    public function testAuthenticateFieldsAreNotString()
    {
        $request = new Request('posts/index', false);
        $request->data = [
            'username' => ['mariano', 'phpnut'],
            'password' => 'my password'
        ];
        $this->assertFalse($this->auth->authenticate($request, $this->response));
        $request->data = [
            'username' => 'mariano',
            'password' => ['password1', 'password2']
        ];
        $this->assertFalse($this->auth->authenticate($request, $this->response));
    }

    /**
     * test the authenticate method
     *
     * @return void
     */
    public function testAuthenticateInjection()
    {
        $request = new Request('posts/index');
        $request->data = [
            'username' => '> 1',
            'password' => "' OR 1 = 1"
        ];
        $this->assertFalse($this->auth->authenticate($request, $this->response));
    }

    /**
     * test authenticate success
     *
     * @return void
     */
    public function testAuthenticateSuccess()
    {
        $request = new Request('posts/index');
        $request->data = [
            'username' => 'mariano',
            'password' => 'password'
        ];
        $result = $this->auth->authenticate($request, $this->response);
        $expected = [
            'id' => 1,
            'username' => 'mariano',
            'created' => new Time('2007-03-17 01:16:23'),
            'updated' => new Time('2007-03-17 01:18:31')
        ];
        $this->assertEquals($expected, $result);
    }

    /**
     * Test that authenticate() includes virtual fields.
     *
     * @return void
     */
    public function testAuthenticateIncludesVirtualFields()
    {
        $users = TableRegistry::get('Users');
        $users->entityClass('TwoFactorAuth\Test\App\Model\Entity\VirtualUser');
        $request = new Request('posts/index');
        $request->data = [
            'username' => 'mariano',
            'password' => 'password'
        ];
        $result = $this->auth->authenticate($request, $this->response);
        $expected = [
            'id' => 1,
            'username' => 'mariano',
            'bonus' => 'bonus',
            'created' => new Time('2007-03-17 01:16:23'),
            'updated' => new Time('2007-03-17 01:18:31')
        ];
        $this->assertEquals($expected, $result);
    }

    /**
     * Test using custom finder
     *
     * @return void
     */
    public function testFinder()
    {
        $request = new Request('posts/index');
        $request->data = [
            'username' => 'mariano',
            'password' => 'password'
        ];
        $this->auth->config([
            'userModel' => 'AuthUsers',
            'finder' => 'auth'
        ]);
        $result = $this->auth->authenticate($request, $this->response);
        $expected = [
            'id' => 1,
            'username' => 'mariano',
        ];
        $this->assertEquals($expected, $result, 'Result should not contain "created" and "modified" fields');
    }

    /**
     * test password hasher settings
     *
     * @return void
     */
    public function testPasswordHasherSettings()
    {
        $this->auth->config('passwordHasher', [
            'className' => 'Default',
            'hashType' => PASSWORD_BCRYPT
        ]);
        $passwordHasher = $this->auth->passwordHasher();
        $result = $passwordHasher->config();
        $this->assertEquals(PASSWORD_BCRYPT, $result['hashType']);
        $hash = password_hash('mypass', PASSWORD_BCRYPT);
        $User = TableRegistry::get('Users');
        $User->updateAll(
            ['password' => $hash],
            ['username' => 'mariano']
        );
        $request = new Request('posts/index');
        $request->data = [
            'username' => 'mariano',
            'password' => 'mypass'
        ];
        $result = $this->auth->authenticate($request, $this->response);
        $expected = [
            'id' => 1,
            'username' => 'mariano',
            'created' => new Time('2007-03-17 01:16:23'),
            'updated' => new Time('2007-03-17 01:18:31')
        ];
        $this->assertEquals($expected, $result);
        $this->auth = new FormAuthenticate($this->ComponentRegistry, [
            'fields' => ['username' => 'username', 'password' => 'password'],
            'userModel' => 'Users'
        ]);
        $this->auth->config('passwordHasher', [
            'className' => 'Default'
        ]);
        $this->assertEquals($expected, $this->auth->authenticate($request, $this->response));
        $User->updateAll(
            ['password' => '$2y$10$/G9GBQDZhWUM4w/WLes3b.XBZSK1hGohs5dMi0vh/oen0l0a7DUyK'],
            ['username' => 'mariano']
        );
        $this->assertFalse($this->auth->authenticate($request, $this->response));
    }

    /**
     * Tests that using default means password don't need to be rehashed
     *
     * @return void
     */
    public function testAuthenticateNoRehash()
    {
        $request = new Request('posts/index');
        $request->data = [
            'username' => 'mariano',
            'password' => 'password'
        ];
        $result = $this->auth->authenticate($request, $this->response);
        $this->assertNotEmpty($result);
        $this->assertFalse($this->auth->needsPasswordRehash());
    }

    /**
     * Tests that not using the Default password hasher means that the password
     * needs to be rehashed
     *
     * @return void
     */
    public function testAuthenticateRehash()
    {
        $this->auth = new FormAuthenticate($this->ComponentRegistry, [
            'userModel' => 'Users',
            'passwordHasher' => 'Weak'
        ]);
        $password = $this->auth->passwordHasher()->hash('password');
        TableRegistry::get('Users')->updateAll(['password' => $password], []);
        $request = new Request('posts/index');
        $request->data = [
            'username' => 'mariano',
            'password' => 'password'
        ];
        $result = $this->auth->authenticate($request, $this->response);
        $this->assertNotEmpty($result);
        $this->assertTrue($this->auth->needsPasswordRehash());
    }

    /**
     * Call a protected method on an object
     *
     * @param object $obj object
     * @param string $name method to call
     * @param array $args arguments to pass to the method
     * @return mixed
     */
    public function protectedMethodCall($obj, $name, array $args = [])
    {
        $class = new \ReflectionClass($obj);
        $method = $class->getMethod($name);
        $method->setAccessible(true);
        return $method->invokeArgs($obj, $args);
    }
}
