<?php
declare(strict_types=1);
namespace In2code\Luxletter\Domain\Repository;

use Doctrine\DBAL\DBALException;
use In2code\Luxletter\Domain\Model\Log;
use In2code\Luxletter\Domain\Model\Newsletter;
use In2code\Luxletter\Domain\Model\User;
use In2code\Luxletter\Utility\DatabaseUtility;
use In2code\Luxletter\Utility\ObjectUtility;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Class LogRepository
 */
class LogRepository extends AbstractRepository
{

    /**
     * @return int
     * @throws DBALException
     */
    public function getNumberOfReceivers(): int
    {
        $connection = DatabaseUtility::getConnectionForTable(Log::TABLE_NAME);
        return (int)$connection->executeQuery(
            'select count(DISTINCT user) from ' . Log::TABLE_NAME .
            ' where deleted=0 and status=' . Log::STATUS_DISPATCH . ';'
        )->fetchColumn(0);
    }

    /**
     * Example result value:
     *  0 => [
     *      'count' => 2,
     *      'properties' => '{"target":"https:\/\/de.wikipedia.org\/wiki\/Haushund"}',
     *      'newsletter' => Newsletter::class
     *      'target' => 'https://de.wikipedia.org/wiki/Haushund'
     *  ]
     *
     * @param int $limit
     * @return array
     * @throws DBALException
     */
    public function getGroupedLinksByHref(int $limit = 8): array
    {
        $connection = DatabaseUtility::getConnectionForTable(Log::TABLE_NAME);
        $results = (array)$connection->executeQuery(
            'select count(*) as count, properties, newsletter from ' . Log::TABLE_NAME .
            ' where deleted=0 and status=' . Log::STATUS_LINKOPENING .
            ' group by properties,newsletter order by count desc limit ' . $limit
        )->fetchAll();
        $nlRepository = ObjectUtility::getObjectManager()->get(NewsletterRepository::class);
        foreach ($results as &$result) {
            $result['target'] = json_decode($result['properties'], true)['target'];
            $result['newsletter'] = $nlRepository->findByUid($result['newsletter']);
        }
        return $results;
    }

    /**
     * @return int
     * @throws DBALException
     */
    public function getOverallOpenings(): int
    {
        $connection = DatabaseUtility::getConnectionForTable(Log::TABLE_NAME);
        return (int)$connection->executeQuery(
            'select count(distinct newsletter, user) from ' . Log::TABLE_NAME .
            ' where deleted = 0' .
            ' and status IN (' . Log::STATUS_NEWSLETTEROPENING . ',' . Log::STATUS_LINKOPENING . ')' . ';'
        )->fetchColumn(0);
    }

    /**
     * @return int
     * @throws DBALException
     */
    public function getOpeningsByClickers(): int
    {
        $connection = DatabaseUtility::getConnectionForTable(Log::TABLE_NAME);
        return (int)$connection->executeQuery(
            'select count(distinct newsletter, user) from ' . Log::TABLE_NAME .
            ' where deleted = 0 and status=' . Log::STATUS_LINKOPENING . ';'
        )->fetchColumn(0);
    }

    /**
     * @return int
     * @throws DBALException
     */
    public function getOverallClicks(): int
    {
        $connection = DatabaseUtility::getConnectionForTable(Log::TABLE_NAME);
        return (int)$connection->executeQuery(
            'select count(uid) from ' . Log::TABLE_NAME .
            ' where deleted = 0 and status=' . Log::STATUS_LINKOPENING . ';'
        )->fetchColumn(0);
    }

    /**
     * @return int
     * @throws DBALException
     */
    public function getOverallUnsubscribes(): int
    {
        $connection = DatabaseUtility::getConnectionForTable(Log::TABLE_NAME);
        return (int)$connection->executeQuery(
            'select count(uid) from ' . Log::TABLE_NAME .
            ' where deleted = 0 and status=' . Log::STATUS_UNSUBSCRIBE . ';'
        )->fetchColumn(0);
    }

    /**
     * @return int
     * @throws DBALException
     */
    public function getOverallMailsSent(): int
    {
        $connection = DatabaseUtility::getConnectionForTable(Log::TABLE_NAME);
        return (int)$connection->executeQuery(
            'select count(uid) from ' . Log::TABLE_NAME .
            ' where deleted = 0 and status=' . Log::STATUS_DISPATCH . ';'
        )->fetchColumn(0);
    }

    /**
     * @return float
     * @throws DBALException
     */
    public function getOverallOpenRate(): float
    {
        $overallSent = $this->getOverallMailsSent();
        $overallOpenings = $this->getOverallOpenings();
        if ($overallSent > 0) {
            return $overallOpenings / $overallSent;
        }
        return 0.0;
    }

    /**
     * @return float
     * @throws DBALException
     */
    public function getOverallClickRate(): float
    {
        $overallOpenings = $this->getOverallOpenings();
        $openingsByClickers = $this->getOpeningsByClickers();
        if ($overallOpenings > 0) {
            return $openingsByClickers / $overallOpenings;
        }
        return 0.0;
    }

    /**
     * @return float
     * @throws DBALException
     */
    public function getOverallUnsubscribeRate(): float
    {
        $overallOpenings = $this->getOverallOpenings();
        $overallUnsubscribes = $this->getOverallUnsubscribes();
        if ($overallOpenings > 0) {
            return $overallUnsubscribes / $overallOpenings;
        }
        return 0.0;
    }

    /**
     * @param Newsletter $newsletter
     * @param User $user
     * @param int $status
     * @return bool
     */
    public function isLogRecordExisting(Newsletter $newsletter, User $user, int $status): bool
    {
        $querybuilder = DatabaseUtility::getQueryBuilderForTable(Log::TABLE_NAME);
        $uid = (int)$querybuilder
            ->select('uid')
            ->from(Log::TABLE_NAME)
            ->where('newsletter=' . $newsletter->getUid() . ' and user=' . $user->getUid() . ' and status=' . $status)
            ->setMaxResults(1)
            ->execute()
            ->fetchColumn(0);
        return $uid > 0;
    }

    /**
     * @param Newsletter $newsletter
     * @param int|int[] $status
     * @param bool $distinctMails
     * @return array
     * @throws \Doctrine\DBAL\Exception
     */
    public function findByNewsletterAndStatus(Newsletter $newsletter, $status, bool $distinctMails = false): array
    {
        $sqlSelectColumns = '*';
        if ($distinctMails) {
            $sqlSelectColumns = 'distinct newsletter, user';
        }
        $sqlWhereStatus = 'status=0';
        if (is_int($status)) {
            $sqlWhereStatus = 'status=' . $status;
        } elseif (is_array($status) && count($status) > 0) {
            $sqlWhereStatus = 'status in (' . implode(',', array_map('intval', $status)) . ')';
        }

        $connection = DatabaseUtility::getConnectionForTable(Log::TABLE_NAME);
        return (array)$connection->executeQuery(
            'select ' . $sqlSelectColumns . ' from ' . Log::TABLE_NAME .
            ' where deleted=0 and ' . $sqlWhereStatus . ' and newsletter=' . $newsletter->getUid()
        )->fetchAll();
    }

    /**
     * @param User $user
     * @param array $statusWhitelist only want logs with this status (overrules any values from $statusBlacklist)
     * @param array $statusBlacklist ignore logs with this status
     * @return array
     * @throws DBALException
     */
    public function findRawByUser(User $user, array $statusWhitelist = [], array $statusBlacklist = []): array
    {
        $connection = DatabaseUtility::getConnectionForTable(Log::TABLE_NAME);
        $sql = 'select * from ' . Log::TABLE_NAME . ' where deleted=0 and user=' . $user->getUid();
        if ($statusWhitelist !== []) {
            $sql .= ' and status in (' . implode(',', $statusWhitelist) . ')';
        } elseif ($statusBlacklist !== []) {
            $sql .= ' and status not in (' . implode(',', $statusBlacklist) . ')';
        }
        $sql .= ' order by crdate desc';
        return (array)$connection->executeQuery($sql)->fetchAll();
    }

    /**
     * @param User $user
     * @return QueryResultInterface
     */
    public function findByUser(User $user): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching($query->equals('user', $user));
        return $query->execute();
    }
}
