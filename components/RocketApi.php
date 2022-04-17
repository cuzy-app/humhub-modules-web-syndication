<?php
/**
 * Rocket
 * @link https://www.cuzy.app
 * @license https://www.cuzy.app/cuzy-license
 * @author [Marc FARRE](https://marc.fun)
 */

namespace humhub\modules\rocket\components;

use ATDev\RocketChat\Channels\Channel as RocketChannel;
use ATDev\RocketChat\Chat as RocketChat;
use ATDev\RocketChat\Roles\Role as RocketRole;
use ATDev\RocketChat\Users\User as RocketUser;
use humhub\modules\rocket\models\ModuleSettings;
use humhub\modules\user\models\User;
use Yii;
use yii\base\Component;
use yii\helpers\BaseInflector;


/**
 * Doc: https://github.com/alekseykuleshov/rocket-chat and https://developer.rocket.chat/reference/api/rest-api/endpoints/team-collaboration-endpoints
 */
class RocketApi extends Component
{
    protected const CACHE_KEY_PREFIX = 'rocketApi';
    protected const CACHE_DURATION = 60 * 60;

    /**
     * @var ModuleSettings module settings
     * Populated in init() function
     */
    public $settings;

    /**
     * @var string[]
     */
    public $rocketUserUsernames;

    /**
     * @var string[]
     */
    public $rocketUserEmails;

    /**
     * @var string[]
     */
    public $rocketRoleNames;

    /**
     * @var string[]
     */
    public $rocketChannelNames;

    /**
     * @var bool
     */
    protected $loggedIn = false;


    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->settings = new ModuleSettings();

        if (!class_exists('Chat')) {
            require Yii::getAlias('@rocket/vendor/autoload.php');
        }

        // Login to Rocket API
        if ($this->settings->apiUrl && $this->settings->apiUserLogin && $this->settings->apiUserPassword) {
            RocketChat::setUrl($this->settings->apiUrl);
            $this->loggedIn = $this->resultIsValid(RocketChat::login($this->settings->apiUserLogin, $this->settings->apiUserPassword), __METHOD__, RocketChat::class);
        }

        parent::init();
    }

    /**
     * @param $result
     * @param string|null $methodName
     * @param string|null $class
     * @return bool
     */
    protected function resultIsValid($result, ?string $methodName = null, ?string $class = null)
    {
        if (!$result) {
            $error = $class ? $class::getError() : $result->getError();
            Yii::error('Rocket module error on API request' . ($methodName ? ' (' . $methodName . ')' : '') . ': ' . $error);
            return false;
        }
        return true;
    }

    /**
     * @param string $rocketRoleName
     * @return bool
     */
    public function createRole(string $rocketRoleName)
    {
        if (
            !$this->loggedIn
            || $this->getRocketRoleId($rocketRoleName) !== null // exists already
        ) {
            return false;
        }

        $rocketRole = new RocketRole();
        $rocketRole->setName(BaseInflector::slug($rocketRoleName));
        $rocketRole->setDescription($rocketRoleName);

        return $this->resultIsValid($rocketRole->create(), __METHOD__);
    }

    /**
     * @param string $roleName
     * @return null|string
     */
    public function getRocketRoleId(string $roleName)
    {
        $this->initRocketRoleNames();
        return array_search(BaseInflector::slug($roleName), $this->rocketRoleNames, true) ?: null;
    }

    /**
     * @return void
     */
    public function initRocketRoleNames($flushCache = false)
    {
        if (!$this->loggedIn || $this->rocketRoleNames !== null) {
            return;
        }

        $cacheKey = static::CACHE_KEY_PREFIX . 'roles';
        if ($flushCache) {
            Yii::$app->cache->delete($cacheKey);
        }
        $this->rocketRoleNames = Yii::$app->cache->getOrSet($cacheKey, function () {
            $roleListing = RocketRole::listing();
            if ($this->resultIsValid($roleListing, __METHOD__, RocketRole::class)) {
                $roles = [];
                /** @var RocketRole $role */
                foreach ($roleListing as $role) {
                    $roles[$role->getRoleId()] = BaseInflector::slug($role->getName());
                }
                return $roles;
            }
            return [];
        }, static::CACHE_DURATION);
    }

    /**
     * @param string $rocketRoleName
     * @return bool
     */
    public function deleteRole(string $rocketRoleName)
    {
        if (
            !$this->loggedIn
            || ($roleId = $this->getRocketRoleId($rocketRoleName)) === null
        ) {
            return false;
        }

        $rocketRole = new RocketRole();
        $rocketRole->setName(BaseInflector::slug($rocketRoleName));

        return $this->resultIsValid($rocketRole->delete($roleId), __METHOD__);
    }

    /**
     * @param string $rocketRoleName
     * @param string $rocketRoleNewName
     * @return bool
     */
    public function renameRole(string $rocketRoleName, string $rocketRoleNewName)
    {
        if (
            !$this->loggedIn
            || ($roleId = $this->getRocketRoleId($rocketRoleName)) === null
        ) {
            return false;
        }

        $rocketRole = new RocketRole();
        $rocketRole->setName(BaseInflector::slug($rocketRoleNewName));

        return $this->resultIsValid($rocketRole->rename($roleId), __METHOD__);
    }

    /**
     * @param User $user
     * @param string $rocketRoleName
     * @return bool
     */
    public function addUserToRole(User $user, string $rocketRoleName)
    {
        if (
            !$this->loggedIn
            || ($userId = $this->getRocketUserId($user)) === null
            || $this->getRocketRoleId($rocketRoleName) === null
        ) {
            return false;
        }

        $rocketUserUsername = $this->rocketUserUsernames[$userId];
        $rocketRole = (new RocketRole())->setName(BaseInflector::slug($rocketRoleName));

        return $this->resultIsValid($rocketRole->addUserToRole($rocketUserUsername), __METHOD__);
    }

    /**
     * Search Rocket User ID from Humhub email and username
     * @param User $humhubUser
     * @return null|string
     */
    public function getRocketUserId(User $humhubUser)
    {
        $this->initRocketUsers();
        $rocketUserId = array_search(BaseInflector::slug($humhubUser->username), $this->rocketUserUsernames, true) ?: null;
        if ($rocketUserId !== false) {
            return $rocketUserId;
        }
        return array_search(BaseInflector::slug($humhubUser->email), $this->rocketUserEmails, true) ?: null;
    }

    /**
     * @return void
     */
    public function initRocketUsers($flushCache = false)
    {
        if (
            !$this->loggedIn
            || ($this->rocketUserUsernames !== null && $this->rocketUserEmails !== null)
        ) {
            return;
        }

        $cacheKey = static::CACHE_KEY_PREFIX . 'users';
        if ($flushCache) {
            Yii::$app->cache->delete($cacheKey);
        }
        $users = Yii::$app->cache->getOrSet($cacheKey, function () {
            $userListing = RocketUser::listing();
            if ($this->resultIsValid($userListing, __METHOD__, RocketUser::class)) {
                $users = [];
                /** @var RocketUser $user */
                foreach ($userListing as $user) {
                    $users[$user->getUserId()] = [
                        'username' => BaseInflector::slug(trim($user->getUsername())),
                        'email' => trim($user->getEmail()),
                    ];
                }
                return $users;
            }
            return [];
        }, static::CACHE_DURATION);

        $this->rocketUserUsernames = [];
        $this->rocketUserEmails = [];
        foreach ($users as $userId => $user) {
            $this->rocketUserUsernames[$userId] = $user['username'];
            $this->rocketUserEmails[$userId] = $user['email'];
        }
    }

    /**
     * @param User $user
     * @param string $rocketRoleName
     * @return bool
     */
    public function removeUserFromRole(User $user, string $rocketRoleName)
    {
        if (
            !$this->loggedIn
            || ($userId = $this->getRocketUserId($user)) === null
            || $this->getRocketRoleId($rocketRoleName) === null
        ) {
            return false;
        }

        $rocketUserUsername = $this->rocketUserUsernames[$userId];
        $rocketRole = (new RocketRole())->setName(BaseInflector::slug($rocketRoleName));

        return $this->resultIsValid($rocketRole->removeUserFromRole($rocketUserUsername), __METHOD__);
    }

    /**
     * @param User $user
     * @param string $rocketChannelName
     * @return bool
     */
    public function inviteUserToChannel(User $user, string $rocketChannelName)
    {
        if (
            !$this->loggedIn
            || ($userId = $this->getRocketUserId($user)) === null
            || ($channelId = $this->getRocketChannelId($rocketChannelName)) === null
        ) {
            return false;
        }

        $rocketUser = new RocketUser($userId);
        $rocketChannel = new RocketChannel($channelId);

        return $this->resultIsValid($rocketChannel->invite($rocketUser), __METHOD__);
    }

    /**
     * @param $channelName
     * @return null|string
     */
    public function getRocketChannelId($channelName)
    {
        $this->initRocketChannelNames();
        return array_search(BaseInflector::slug($channelName), $this->rocketChannelNames, true) ?: null;
    }

    /**
     * @return void
     */
    public function initRocketChannelNames($flushCache = false)
    {
        if (!$this->loggedIn || $this->rocketChannelNames !== null) {
            return;
        }

        $cacheKey = static::CACHE_KEY_PREFIX . 'channels';
        if ($flushCache) {
            Yii::$app->cache->delete($cacheKey);
        }
        $this->rocketChannelNames = Yii::$app->cache->getOrSet($cacheKey, function () {
            $channelListing = RocketChannel::listing();
            if ($this->resultIsValid($channelListing, __METHOD__, RocketChannel::class)) {
                $channels = [];
                /** @var RocketChannel $channel */
                foreach ($channelListing as $channel) {
                    $channels[$channel->getChannelId()] = BaseInflector::slug($channel->getName());
                }
                return $channels;
            }
            return [];
        }, static::CACHE_DURATION);
    }

    /**
     * @param User $user
     * @param string $rocketChannelName
     * @return bool
     */
    public function kickUserOutOfChannel(User $user, string $rocketChannelName)
    {
        if (
            !$this->loggedIn
            || ($userId = $this->getRocketUserId($user)) === null
            || ($channelId = $this->getRocketChannelId($rocketChannelName)) === null
        ) {
            return false;
        }

        $rocketUser = new RocketUser($userId);
        $rocketChannel = new RocketChannel($channelId);

        return $this->resultIsValid($rocketChannel->kick($rocketUser), __METHOD__);
    }

    /**
     * @return void
     */
    public function logout()
    {
        if ($this->loggedIn) {
            RocketChat::logout();
        }
    }
}