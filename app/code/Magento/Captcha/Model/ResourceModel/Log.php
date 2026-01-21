<?php
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Captcha\Model\ResourceModel;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PhpDb\Sql\Expression;

/**
 * Log Attempts resource
 *
 */
class Log extends AbstractDb
{
    /**
     * Remote Address log type
     */
    public const TYPE_REMOTE_ADDRESS = 1;

    /**
     * Type User Login Name
     */
    public const TYPE_LOGIN = 2;

    /**
     * Date helper
     *
     * @var DateTime
     */
    protected $_coreDate;

    /**
     * @var RemoteAddress
     */
    protected $_remoteAddress;

    /**
     * @param Context $context
     * @param DateTime $coreDate
     * @param RemoteAddress $remoteAddress
     * @param string $connectionName
     */
    public function __construct(
        Context $context,
        DateTime $coreDate,
        RemoteAddress $remoteAddress,
        $connectionName = null
    ) {
        $this->_coreDate = $coreDate;
        $this->_remoteAddress = $remoteAddress;
        parent::__construct($context, $connectionName);
    }

    /**
     * Define main table
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_setMainTable('captcha_log');
    }

    /**
     * Save or Update count Attempts
     *
     * @param string|null $login
     * @return $this
     * @throws LocalizedException
     */
    public function logAttempt($login)
    {
        if ($login != null) {
            $this->getConnection()->insertOnDuplicate(
                $this->getMainTable(),
                [
                    'type' => self::TYPE_LOGIN,
                    'value' => $login,
                    'count' => 1,
                    'updated_at' => $this->_coreDate->gmtDate()
                ],
                ['count' => new Expression('count+1'), 'updated_at']
            );
        }
        $ip = $this->_remoteAddress->getRemoteAddress();
        if ($ip != null) {
            $this->getConnection()->insertOnDuplicate(
                $this->getMainTable(),
                [
                    'type' => self::TYPE_REMOTE_ADDRESS,
                    'value' => $ip,
                    'count' => 1,
                    'updated_at' => $this->_coreDate->gmtDate()
                ],
                ['count' => new Expression('count+1'), 'updated_at']
            );
        }
        return $this;
    }

    /**
     * Delete User attempts by login
     *
     * @param string $login
     * @return $this
     * @throws LocalizedException
     */
    public function deleteUserAttempts($login)
    {
        if ($login != null) {
            $this->getConnection()->delete(
                $this->getMainTable(),
                ['type = ?' => self::TYPE_LOGIN, 'value = ?' => $login]
            );
        }
        $ip = $this->_remoteAddress->getRemoteAddress();
        if ($ip != null) {
            $this->getConnection()->delete(
                $this->getMainTable(),
                ['type = ?' => self::TYPE_REMOTE_ADDRESS, 'value = ?' => $ip]
            );
        }

        return $this;
    }

    /**
     * Get count attempts by ip
     *
     * @return null|int
     * @throws LocalizedException
     */
    public function countAttemptsByRemoteAddress()
    {
        $ip = $this->_remoteAddress->getRemoteAddress();
        if (!$ip) {
            return 0;
        }
        $connection = $this->getConnection();
        $select = $connection->select()->from(
            $this->getMainTable(),
            'count'
        )->where(
            'type = ?',
            self::TYPE_REMOTE_ADDRESS
        )->where(
            'value = ?',
            $ip
        );
        return $connection->fetchOne($select);
    }

    /**
     * Get count attempts by user login
     *
     * @param string $login
     * @return null|int
     * @throws LocalizedException
     */
    public function countAttemptsByUserLogin($login)
    {
        if (!$login) {
            return 0;
        }
        $connection = $this->getConnection();
        $select = $connection->select()->from(
            $this->getMainTable(),
            'count'
        )->where(
            'type = ?',
            self::TYPE_LOGIN
        )->where(
            'value = ?',
            $login
        );
        return $connection->fetchOne($select);
    }

    /**
     * Delete attempts with expired in update_at time
     *
     * @return void
     * @throws LocalizedException
     */
    public function deleteOldAttempts()
    {
        $this->getConnection()->delete(
            $this->getMainTable(),
            ['updated_at < ?' => $this->_coreDate->gmtDate(null, time() - 60 * 30)]
        );
    }
}
