<?php namespace dunksjunk\ADAuth;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\Model;

class ADAuthUserProvider implements UserProvider {

  /**
   * Configuration Parameters
   */

  /**
   * adAuthServer
   * List of servers to connect to for authentication
   * @var array
   */
    protected $adAuthServer;

  /**
   * adAuthPort
   * Server port. Default 389 or 636 for SSL
   * @var string
   */
  protected $adAuthPort;

  /**
   * adAuthShortDomain
   * For prepending to account name
   * @var string
   */
  protected $adAuthShortDomain;

  /**
   * adAuthModel
   * User Model to return
   * @var string
   */
  protected $adAuthModel;

  /**
   * adAuthDBFallback
   * Auth DB user if user not found on Active Directory
   *
   * @var boolean
   */
  protected $adAuthDBFallback;

  /**
   * adAuthCreateNew
   * If DB user not found, but Active Directory user is, create DB User
   *
   * @var boolean
   */
  protected $adAuthCreateNew;

  /**
   * adAuthUserDefaults
   * Field defaults if generating new user
   *
   * @var array
   */
  protected $adAuthUserDefaults;

  /**
   * Internal Parameters
   */

  /**
   * Server Connection
   *
   * @var resource
   */
  protected $adConnection;


  /**
   * Pull up a new AD User Provider
   */
  public function __construct() {
    $this->adAuthModel = \Config::get('auth.model');
    $this->fetchConfig();
  }

  /**
   * Fetch user from database based on id
   * @param integer
   * @return object
   */
  public function retrieveById($identifier) {
    return $this->createModel()->newQuery()->find($identifier);
  }

  /**
   * Fetch user from database on id & token
   * @param integer
   * @param integer
   * @return object
   */
  public function retrieveByToken($identifier, $token) {
    $model = $this->createModel();

    return $model->newQuery()
        ->where($model->getKeyName(), $identifier)
        ->where($model->getRememberTokenName(), $token)
        ->first();
  }

  /**
   * Set 'remember me' token on user model
   * @param UserContact
   * @param string
   */
  public function updateRememberToken(UserContract $user, $token) {
    $user->setRememberToken($token);
  }

  /**
   * Fetch user from databased on credentials supplied
   * @param array`
   * @return object
   */
  public function retrieveByCredentials(array $credentials) {
    $query = $this->createModel()->newQuery();
    $usernameField = '';
    $usernameValue = '';

    foreach( array_except($credentials, [ 'password' ]) as $key => $value ) {
      $usernameField = $key;
      $usernameValue = $value;
      $query->where($usernameField, '=', $usernameValue);
    }

    return $this->findUserRecord($query, $usernameField, $usernameValue, $credentials[ 'password' ]);
  }

  /**
   * Validate user object based on supplied credentials
   * @param Model
   * @param array
   * @return boolean
   */
  public function validateCredentials(Model $user, array $credentials) {
    $username = array_first($credentials, function($key) {
      return $key != 'password';
    });
    $password = array_first($credentials, function($key) {
      return $key == 'password';
    });

    try {
      $this->adConnection = $this->serverConnect();
      // if it binds, it finds
      $adResult = @ldap_bind($this->adConnection, $this->adAuthShortDomain . '\\' . $username, $password);
    }catch( Exception $e ) {
      throw new Exception('Can not connect to Active Directory Server.');
    }

    ldap_unbind($this->adConnection);

    if( $this->adAuthDBFallback && ! $adResult && \Hash::check($password, $user->getAuthPassword()) ) {
      $adResult = true;
    }

    if( $this->adAuthCreateNew && $adResult && $user->exists === false ) {
      $user->save();
    }		
		
    return $adResult;
  }

  /**
   * Find user Record or Create new instance, if configuration allows
   * @param object
   * @param string
   * @param string
   * @param string
   * @return object
   */
  private function findUserRecord($query, $usernameField, $usernameValue, $password) {
    $result = $query->first();
    if( $this->adAuthCreateNew && $result === null ) {
      return $this->createModel()->newInstance(array_merge($this->adAuthUserDefaults, [ $usernameField => $usernameValue, 'password' => \Hash::make($password) ]));
    }
    return $result;
  }
  
  /**
   * Load config files or set defaults
   */
  private function fetchConfig() {
    $this->adAuthServer = \Config::get('adauth.adAuthServer', array('localhost'));
    $this->adAuthPort = \Config::get('adauth.adAuthPort', 389);
    $this->adAuthShortDomain = \Config::get('adauth.adAuthShortDomain', 'mydomain');
    $this->adAuthDBFallback = \Config::get('adauth.adAuthDBFallback', false);
    $this->adAuthCreateNew = \Config::get('adauth.adAuthCreateNew', false);
    $this->adAuthUserDefaults = \Config::get('adauth.adAuthUserDefaults', [ ]);
    $this->adAuthModel = \Config::get('auth.model', 'App\User');
  }

  /**
   * Connect to ADS Server or fail
   * @param none
   * @return resource
   */
  private function serverConnect() {
    $adConnectionString = '';

    if( is_array($this->adAuthServer) ) {
      foreach( $this->adAuthServer as $server ) {
        $adConnectionString .= 'ldap://' . $server . ':' . $this->adAuthPort . '/ ';
      }
    } else {
      $adConnectionString = $this->adAuthServer;
    }

    $this->adConnection = ldap_connect($adConnectionString);

    ldap_set_option($this->adConnection, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($this->adConnection, LDAP_OPT_REFERRALS, 0);

    return $this->adConnection;
  }

  /**
   * Create User Model Object
   * @param none
   * @return object
   */
  public function createModel() {
    $class = '\\' . ltrim($this->adAuthModel, '\\');
    return new $class;
  }

}
