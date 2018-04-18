<?php
namespace app\models;

use Yii;
use yii\base\NotSupportedException;
use yii\behaviors\TimestampBehavior;
use yii\db\Query;
use yii\web\IdentityInterface;

/**
 * User model
 *
 * @property integer $id
 * @property string $username
 * @property string $nickname
 * @property string $password_hash
 * @property string $password_reset_token
 * @property string $email
 * @property string $auth_key
 * @property integer $status
 * @property integer $role
 * @property integer $language
 * @property string $created_at
 * @property string $updated_at
 * @property string $password write-only password
 */
class User extends ActiveRecord implements IdentityInterface
{
    const STATUS_DELETED = 0;
    const STATUS_ACTIVE = 10;

    /**
     * 比赛账户，该账户用于参加比赛，跟普通用户的区别在于禁止私自修改个人信息，用户名、昵称、密码
     * 普通用户
     * 管理员
     * 超级管理员
     */
    const ROLE_PLAYER = 0;
    const ROLE_USER = 10;
    const ROLE_MODERATOR = 20;
    const ROLE_ADMIN = 30;

    public $oldPassword;
    public $newPassword;
    public $verifyPassword;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%user}}';
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'timestamp' => $this->timeStampBehavior(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            ['status', 'default', 'value' => self::STATUS_ACTIVE],
            ['language', 'integer'],
            [['username', 'nickname'], 'required'],
            [['nickname'], 'string', 'max' => 16],
            ['username', 'match', 'pattern' => '/^(?!_)(?!.*?_$)(?!\d{4,32}$)[a-z\d_]{4,32}$/i', 'message' => '用户名只能以数字、字母、下划线，且非纯数字，长度在 4 - 32 位之间'],
            ['username', 'unique', 'targetClass' => '\app\models\User', 'message' => 'This username has already been taken.'],
            ['status', 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_DELETED]],

            // oldPassword is validated by validateOldPassword()
            [['oldPassword'], 'validateOldPassword'],
            [['verifyPassword'], 'compare', 'compareAttribute' => 'newPassword'],
            [['oldPassword', 'verifyPassword', 'newPassword'], 'required']
        ];
    }

    public function validateOldPassword()
    {
        $user = self::findOne($this->id);

        if (!$user || !$user->validatePassword($this->oldPassword)) {
            Yii::$app->getSession()->setFlash('error', 'Incorrect old password.');
            $this->addError('password', 'Incorrect old password.');
        }
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'username' => Yii::t('app', 'Username'),
            'nickname' => Yii::t('app', 'Nickname'),
            'password' => Yii::t('app', 'Password'),
            'oldPassword' => Yii::t('app', 'Old Password'),
            'newPassword' => Yii::t('app', 'New Password'),
            'verifyPassword' => Yii::t('app', 'Verify Password'),
            'status' => Yii::t('app', 'Status'),
            'email' => Yii::t('app', 'Email'),
            'created_at' => Yii::t('app', 'Created At'),
            'updated_at' => Yii::t('app', 'Updated At'),
            'avatar' => Yii::t('app', 'User Icon'),
        ];
    }

    public function scenarios()
    {
        return [
            'default' => ['username', 'email'],
            'profile' => ['nickname'],
            'security' => ['oldPassword', 'newPassword', 'verifyPassword'],
        ];
    }

    /**
     * @inheritdoc
     */
    public static function findIdentity($id)
    {
        return static::findOne(['id' => $id, 'status' => self::STATUS_ACTIVE]);
    }

    /**
     * @inheritdoc
     */
    public static function findIdentityByAccessToken($token, $type = null)
    {
        throw new NotSupportedException('"findIdentityByAccessToken" is not implemented.');
    }

    /**
     * Finds user by username
     *
     * @param string $username
     * @return static|null
     */
    public static function findByUsername($username)
    {
        if (is_numeric($username)) {
            $param = 'id';
        } elseif (strpos($username, '@')) {
            $param = 'email';
        } else {
            $param = 'username';
        }
        return static::findOne([$param => $username, 'status' => self::STATUS_ACTIVE]);
    }

    /**
     * Finds user by password reset token
     *
     * @param string $token password reset token
     * @return static|null
     */
    public static function findByPasswordResetToken($token)
    {
        if (!static::isPasswordResetTokenValid($token)) {
            return null;
        }

        return static::findOne([
            'password_reset_token' => $token,
            'status' => self::STATUS_ACTIVE,
        ]);
    }

    /**
     * Finds out if password reset token is valid
     *
     * @param string $token password reset token
     * @return bool
     */
    public static function isPasswordResetTokenValid($token)
    {
        if (empty($token)) {
            return false;
        }

        $timestamp = (int) substr($token, strrpos($token, '_') + 1);
        $expire = Yii::$app->params['user.passwordResetTokenExpire'];
        return $timestamp + $expire >= time();
    }

    /**
     * @inheritdoc
     */
    public function getId()
    {
        return $this->getPrimaryKey();
    }

    /**
     * @inheritdoc
     */
    public function getAuthKey()
    {
        return $this->auth_key;
    }

    /**
     * @inheritdoc
     */
    public function validateAuthKey($authKey)
    {
        return $this->getAuthKey() === $authKey;
    }

    /**
     * Validates password
     *
     * @param string $password password to validate
     * @return bool if password provided is valid for current user
     */
    public function validatePassword($password)
    {
        return Yii::$app->security->validatePassword($password, $this->password_hash);
    }

    /**
     * Generates password hash from password and sets it to the model
     *
     * @param string $password
     */
    public function setPassword($password)
    {
        $this->password_hash = Yii::$app->security->generatePasswordHash($password);
    }

    public static function setLanguage($language)
    {
        return Yii::$app->db->createCommand()->update('{{%user}}', [
            'language' => $language
        ], ['id' => Yii::$app->user->id])->execute();
    }

    /**
     * Generates "remember me" authentication key
     */
    public function generateAuthKey()
    {
        $this->auth_key = Yii::$app->security->generateRandomString();
    }

    /**
     * Generates new password reset token
     */
    public function generatePasswordResetToken()
    {
        $this->password_reset_token = Yii::$app->security->generateRandomString() . '_' . time();
    }

    /**
     * Removes password reset token
     */
    public function removePasswordResetToken()
    {
        $this->password_reset_token = null;
    }

    /**
     * 获取个人基本资料
     * @return \yii\db\ActiveQuery
     */
    public function getProfile()
    {
        return $this->hasOne(UserProfile::className(), ['user_id' => 'id']);
    }

    /**
     *
     */
    public function getSolutionStats()
    {
        $data = Yii::$app->db->createCommand(
            'SELECT problem_id, language, result FROM {{%solution}} WHERE created_by=:uid',
            [':uid' => $this->id]
        )->queryAll();

        $ac_count = 0;
        $tle_count = 0;
        $ce_count = 0;
        $wa_count = 0;
        $all_count = count($data);
        $solved_problem = [];
        $unsolved_problem = [];
        foreach ($data as $v) {
            if ($v['result'] == Solution::OJ_AC) {
                array_push($solved_problem, $v['problem_id']);
            } else {
                array_push($unsolved_problem, $v['problem_id']);
            }

            if ($v['result'] == Solution::OJ_WA) {
                $wa_count++;
            } else if ($v['result'] == Solution::OJ_AC) {
                $ac_count++;
            } else if ($v['result'] == Solution::OJ_CE) {
                $ce_count++;
            } else if ($v['result'] == Solution::OJ_TL) {
                $tle_count++;
            }
        }
        $solved_problem = array_unique($solved_problem);
        $unsolved_problem = array_unique($unsolved_problem);
        $unsolved_problem = array_diff($unsolved_problem, $solved_problem);
        $solved_problem = array_values($solved_problem);
        $unsolved_problem = array_values($unsolved_problem);
        return [
            'ac_count' => $ac_count,
            'ce_count' => $ce_count,
            'wa_count' => $wa_count,
            'tle_count' => $tle_count,
            'all_count' => $all_count,
            'solved_problem' => $solved_problem,
            'unsolved_problem' => $unsolved_problem
        ];
    }
}
