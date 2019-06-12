<?php
namespace DirectMailTeam\DirectMail\Hooks;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * JumpUrl processing hook on TYPO3\CMS\Frontend\Http\RequestHandler
 *
 * @author		Ivan Kartolo <ivan.kartolo@gmail.com>
 *
 * @package 	TYPO3
 * @subpackage 	tx_directmail
 */
class JumpurlController
{

    /**
     * Get the url to jump to as set by Direct Mail
     *
     * @return	void
     * @throws \Exception
     */
    public function preprocessRequest($parameter, $parentObject)
    {
        $jumpUrlVariables = GeneralUtility::_GET();

        $mid = $jumpUrlVariables['mid'];
        $rid = $jumpUrlVariables['rid'];
        $aC  = $jumpUrlVariables['aC'];

        $responseType = 0;
        if ($mid) {
            // overwrite the jumpUrl with the one from the &jumpurl= get parameter
            $jumpurl = $jumpUrlVariables['jumpurl'];

            // this will split up the "rid=f_13667", where the first part
            // is the DB table name and the second part the UID of the record in the DB table
            $recipientTable = '';
            $recipientUid = '';
            if (!empty($rid)) {
                list($recipientTable, $recipientUid) = explode('_', $rid);
            }

            $urlId = 0;

            if (MathUtility::canBeInterpretedAsInteger($jumpurl)) {


                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_dmail');
                $resMailing = $queryBuilder->select('mailContent','page','authcode_fieldList')
                    ->from('sys_dmail')
                    ->where(
                        $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($mid, \PDO::PARAM_INT))
                    )
                    ->execute()
                    ->fetchAll();

                foreach ($resMailing as  $row) {
                    $mailContent = unserialize(base64_decode($row['mailContent']));
                    $urlId = $jumpurl;
                    if ($jumpurl >= 0) {
                        // Link (number)
                        $responseType = 1;
                        $jumpurl = $mailContent['html']['hrefs'][$urlId]['absRef'];
                    } else {
                        // Link (number, plaintext)
                        $responseType = 2;
                        $jumpurl = $mailContent['plain']['link_ids'][abs($urlId)];
                    }
                    $jumpurl = htmlspecialchars_decode(urldecode($jumpurl));

                    switch ($recipientTable) {
                        case 't':
                            $theTable = 'tt_address';
                            break;
                        case 'f':
                            $theTable = 'fe_users';
                            break;
                        default:
                            $theTable = '';
                    }

                    if ($theTable) {
                        $recipRow = $this->getRawRecord($theTable, $recipientUid);
                        if (is_array($recipRow)) {
                            $authCode = GeneralUtility::stdAuthCode($recipRow[0], ($row['authcode_fieldList'] ? $row['authcode_fieldList'] : 'uid'));

                            // check if supplied aC identical with counted authCode
                            if (($aC != '') && ($aC == $authCode)) {
                                $rowFieldsArray = explode(',', $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['direct_mail']['defaultRecipFields']);
                                if ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['direct_mail']['addRecipFields']) {
                                    $rowFieldsArray = array_merge($rowFieldsArray, explode(',', $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['direct_mail']['addRecipFields']));
                                }

                                reset($rowFieldsArray);
                                foreach ($rowFieldsArray as $substField) {
                                    $jumpurl = str_replace('###USER_' . $substField . '###', $recipRow[0][$substField], $jumpurl);
                                }
                                // Put in the tablename of the userinformation
                                $jumpurl = str_replace('###SYS_TABLE_NAME###', substr($theTable, 0, 1), $jumpurl);
                                // Put in the uid of the mail-record
                                $jumpurl = str_replace('###SYS_MAIL_ID###', $mid, $jumpurl);

                                // If authCode is provided, keep it.
                                $jumpurl = str_replace('###SYS_AUTHCODE###', $aC, $jumpurl);

                                // Auto Login an FE User, only possible if we're allowed to set the $_POST variables and
                                // in the authcode_fieldlist the field "password" is computed in as well
                                // TODO: add a switch in Direct Mail configuration to decide if this option should be enabled by default
                                if ($theTable == 'fe_users' && $aC != '' && $aC == $authCode && GeneralUtility::inList($row['authcode_fieldList'], 'password')) {
                                    $_POST['user'] = $recipRow[0]['username'];
                                    $_POST['pass'] = $recipRow[0]['password'];
                                    $_POST['pid']  = $recipRow[0]['pid'];
                                    $_POST['logintype'] = 'login';
                                }
                            } else {
                                throw new \Exception('authCode: Calculated authCode did not match the submitted authCode. varDump = '.var_dump($recipRow).' $recipientUid = '.$recipientUid.' theTable = '.$theTable.' authcode_fieldList'.$row['authcode_fieldList'].' AC = '. $aC .' AuthCode = '. $authCode, 1376899631);
                            }
                        }
                    }
                }
                if (!$jumpurl) {
                    die('Error: No further link. Please report error to the mail sender.');
                } else {
                    $jumpurl = str_replace('#', '%23', $jumpurl);
                    // jumpurl has been validated by lookup of id in direct_mail tables
                    // for this reason it is save to set the juHash
                    // set juHash as done for external_url in core: http://forge.typo3.org/issues/46071
                    GeneralUtility::_GETset(GeneralUtility::hmac($jumpurl, 'jumpurl'), 'juHash');
                }
            } else {
                // jumpUrl is not an integer -- then this is a URL, that means that the "dmailerping"
                // functionality was used to count the number of "opened mails" received (url, dmailerping)

                // Check if jumpurl is a valid link to a "dmailerping.gif"
                // Make $checkPath an absolute path pointing to dmailerping.gif so it can get checked via ::isAllowedAbsPath()
                // and remove an eventual "/" at beginning of $jumpurl (because PATH_site already contains "/" at the end)
                $checkPath = PATH_site . preg_replace('#^/#', '', $jumpurl);

                // Now check if $checkPath is a valid path and points to a "/dmailerping.gif"
                if (preg_match('#/dmailerping\\.(gif|png)$#', $checkPath) && GeneralUtility::isAllowedAbsPath($checkPath)) {
                    // set juHash as done for external_url in core: http://forge.typo3.org/issues/46071
                    GeneralUtility::_GETset(GeneralUtility::hmac($jumpurl, 'jumpurl'), 'juHash');
                    $responseType = -1;
                } elseif (GeneralUtility::isValidUrl($jumpurl) && preg_match('#^(http://|https://)#', $jumpurl)) {
                    // Also allow jumpurl to be a valid URL
                    GeneralUtility::_GETset(GeneralUtility::hmac($jumpurl, 'jumpurl'), 'juHash');
                    $responseType = -1;
                }

                // to count the dmailerping correctly, we need something unique
                $recipientUid = $aC;
            }

            if ($responseType != 0) {

                $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
                $databaseConnectionSysDamilMaillog = $connectionPool->getConnectionForTable('sys_dmail_maillog');
                $databaseConnectionSysDamilMaillog->insert(
                    'sys_dmail_maillog',
                    [
                        'mid'           => intval($mid),
                        'tstamp'        => time(),
                        'url'           => $jumpurl,
                        'response_type' => intval($responseType),
                        'url_id'        => intval($urlId),
                        'rtbl'            => $recipientTable,
                        'rid'            => $recipientUid
                    ]
                );
            }
        }

        // finally set the jumpURL to the TSFE object
        if (!empty($jumpurl)) {
            GeneralUtility::_GETset($jumpurl, 'jumpurl');
        }
    }

    /**
     * Returns record no matter what - except if record is deleted
     *
     * @param string $table The table name to search
     * @param int $uid The uid to look up in $table
     * @param string $fields The fields to select, default is "*"
     *
     * @return mixed Returns array (the record) if found, otherwise blank/0 (zero)
     * @see getPage_noCheck()
     */
    public function getRawRecord($table, $uid, $fields = '*')
    {
        $uid = (int)$uid;
        if ($uid > 0) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
            $res = $queryBuilder->select($fields)
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0))
                )
                ->execute();

            $row = $res->fetchAll();

            if ($row) {
                if (is_array($row)) {
                    return $row;
                }
            }
        }
        return 0;
    }


}
