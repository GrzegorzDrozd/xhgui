<?php
class Xhgui_Storage_PDO implements \Xhgui_StorageInterface, \Xhgui_WatchedFunctionsStorageInterface {

    /**
     * @var \PDO
     */
    protected $connection;

    /**
     * PDO constructor.
     * @param $config
     */
    public function __construct($config)
    {
        $this->connection = new \PDO(
            $config['db.dsn'],
            !empty($config['db.user'])      ? $config['db.user'] : null,
            !empty($config['db.password'])  ? $config['db.password'] : null,
            !empty($config['db.options'])   ? $config['db.options'] : []
        );
        $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @param \Xhgui_Storage_Filter $filter
     * @param bool $projections
     * @return \Xhgui_Storage_ResultSet
     */
    public function find(\Xhgui_Storage_Filter $filter, $projections = false)
    {
        list($query, $params) = $this->getQuery($filter, false);
//        print '<pre>';
//        print_r($query);
//        print_r($params);
//        print '</pre>';
//        exit;
        try {
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);

        } catch (\Exception $e) {
            print_r($e->getMessage());
            exit;
        }

        $tmp = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $tmp[$row['id']] = $row;
            $tmp[$row['id']]['profile']    = json_decode($row['profiles'], true);
            $tmp[$row['id']]['meta']       = json_decode($row['meta'], true);
        }
        
        return new \Xhgui_Storage_ResultSet($tmp);
    }

    /**
     * @param \Xhgui_Storage_Filter $filter
     * @param bool $count
     * @return array
     */
    protected function getQuery(\Xhgui_Storage_Filter $filter, $count = false) {
        $params = [];

        if ($count === true) {
            $columns = ' count(*) as c ';
        } else {
            $columns = ' p.*, i.*, m.*, p.profile_id as _id, main_wt as duration ';
        }

        $sql = "
select 
    $columns
from 
    profiles as p left join 
    profiles_info as i on (p.profile_id = i.id) LEFT JOIN
    profiles_meta as m on (p.profile_id = m.profile_id)
";

        $where = [];

        foreach([
            'url'               => 'url',
            'method'            => 'method',
            'application'       => 'application',
            'version'           => 'version',
            'branch'            => 'branch',
            'controller'        => 'controller',
            'action'            => 'action',
            ] as $dbField => $field) {

            $method = 'get'.ucfirst($field);

            if ($filter->{$method}()) {
                $where[]        = ' '.$dbField.' = :'.$field.' ';
                $params[$field]  = $filter->{$method}();
            }
        }
        
        if ($filter->getStartDate()) {
            $where[]                = ' request_time >= datetime(:startDate)';
            $params['startDate']   = $this->getDateTimeFromString($filter->getStartDate())->format('Y-m-d H:i:s');
        }

        if ($filter->getEndDate()) {
            $where[]                = ' request_time <= datetime(:endDate)';
            $params['endDate']   = $this->getDateTimeFromString($filter->getEndDate())->format('Y-m-d H:i:s');
        }

        if (!empty($where)) {
            $sql .= ' WHERE '.join(' AND ', $where);
        }

        if ($count === true) {
            return [$sql, $params];
        }

        switch ($filter->getSort()) {
            case 'ct':
                $sql .= ' order by main_ct';
                break;

            case 'wt':
                $sql .= ' order by main_wt';
                break;

            case 'cpu':
                $sql .= ' order by main_cpu';
                break;

            case 'mu':
                $sql .= ' order by main_mu';
                break;

            case 'pmu':
                $sql .= ' order by main_pmu';
                break;

            case 'controller':
                $sql .= ' order by controller';
                break;

            case 'action':
                $sql .= ' order by action';
                break;

            case 'application':
                $sql .= ' order by application';
                break;

            case 'branch':
                $sql .= ' order by branch';
                break;

            case 'version':
                $sql .= ' order by version';
                break;

            case 'time':
            default:
                $sql .= ' order by request_time';
                break;
        }

        switch ($filter->getDirection()) {
            case 'asc':
                $sql .= ' asc ';
                break;

            default:
            case 'desc':
                $sql .= ' desc ';
                break;
        }

        if ($filter->getPerPage()) {
            $sql            .= ' LIMIT :limit ';
            $params['limit'] = $filter->getPerPage();
        }

        if ($filter->getPage()) {
            $sql                .= ' OFFSET :offset ';
            $params['offset']   = ($filter->getPerPage()*($filter->getPage()-1));
        }

        return [$sql, $params];
    }

    public function aggregate(\Xhgui_Storage_Filter $filter, $col, $percentile = 1)
    {
        $stmt = $this->connection->prepare('select 
    * 
from 
    profiles_info
where 
    url = :url
');
        $stmt->execute(['url'=> $filter->getUrl()]);
        $aggregatedData = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $date = new \DateTime($row['request_time']);
            $formattedDate = $date->format('Y-m-d');
            if (empty($aggregatedData[$date->format('Y-m-d')])) {
                $aggregatedData[$date->format('Y-m-d')] = [
                    'wall_times'    => [],
                    'cpu_times'     => [],
                    'mu_times'      => [],
                    'pmu_times'     => [],
                    'row_count'     => 0
                ];
            }

            $aggregatedData[$formattedDate]['wall_times'][] = $row['main_wt'];
            $aggregatedData[$formattedDate]['cpu_times'][]  = $row['main_ct'];
            $aggregatedData[$formattedDate]['mu_times'][]   = $row['main_mu'];
            $aggregatedData[$formattedDate]['pmu_times'][]  = $row['main_pmu'];
            $aggregatedData[$formattedDate]['row_count']++;
            $aggregatedData[$formattedDate]['_id']          = $formattedDate;
            $aggregatedData[$formattedDate]['raw_index']    = $aggregatedData[$formattedDate]['row_count']*($percentile/100);
        }

        $return = [
            'ok'    => 1,
            'result'=> array_values($aggregatedData),
        ];
        return $return;
    }


    public function count(\Xhgui_Storage_Filter $filter) {
        list($query, $params) = $this->getQuery($filter, true);
        try {
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);

        } catch (\Exception $e) {
            print_r($e->getMessage());
            exit;
        }

        $ret = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!empty($ret['c'])) {
            return $ret['c'];
        }
        return 0;
    }

    public function findOne($id)
    {
        $stmt = $this->connection->prepare('
select 
    * 
from 
    profiles as p left join 
    profiles_info as i on (p.profile_id = i.id) LEFT JOIN
    profiles_meta as m on (p.profile_id = m.profile_id)
where 
    p.profile_id = :id
');

        $stmt->execute(['id'=>$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $row['profile'] = json_decode($row['profiles'], true);
        $row['meta']    = json_decode($row['meta'], true);
        $row['_id']     = $id;

        return $row;
    }

    public function remove($id)
    {
        $this->connection->beginTransaction();
        try {
            $profileStmt = $this->connection->prepare('delete from profiles where profile_id = :id');
            $profileStmt->execute(['id'=>$id]);

            $metaStmt = $this->connection->prepare('delete from profiles_meta where profile_id = :id');
            $metaStmt->execute(['id'=>$id]);

            $infoStmt = $this->connection->prepare('delete from profiles_info where id = :id');
            $infoStmt->execute(['id'=>$id]);
            
            $this->connection->commit();
        } catch (\Exception $e) {
            $this->connection->rollBack();
        }
    }

    /**
     *
     */
    public function drop()
    {
        $this->connection->exec('delete from profiles');
        $this->connection->exec('delete from profiles_meta');
        $this->connection->exec('delete from profiles_info');
    }

    public function getWatchedFunctions()
    {
        $stmt = $this->connection->query('select * from watched order by name desc');
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function addWatchedFunction($name)
    {
        $name = trim($name);
        if (empty($name)) {
            return false;
        }
        $stmt = $this->connection->prepare('INSERT INTO watched (name) VALUES (:name)');
        $stmt->execute(['name'=>trim($name)]);
        return true;
    }

    public function updateWatchedFunction($id, $name)
    {
        $stmt = $this->connection->prepare('update watched set name=:name where id = :id');
        $stmt->execute(['id'=>$id, 'name'=>$name]);
    }

    public function removeWatchedFunction($id)
    {
        $stmt = $this->connection->prepare('delete from watched where id = :id');
        $stmt->execute(['id'=>$id]);
    }

    /**
     * @param string|int $date
     * @return \DateTime
     */
    protected function getDateTimeFromString($date) {

        try {
            $datetime = \DateTime::createFromFormat('Y-m-d H:i:s', $date);
            if ($datetime instanceof \DateTime) {
                return $datetime;
            }
        } catch (\Exception $e){
        }

        try {
            $datetime = \DateTime::createFromFormat('U', $date);
            if ($datetime instanceof \DateTime) {
                return $datetime;
            }
        } catch (\Exception $e){
        }

        throw new \InvalidArgumentException('Unable to parse date');
    }

    public function insert(array $data) {
        // TODO: Implement insert() method.
    }

    public function update($_id, array $data) {
        // TODO: Implement update() method.
    }
}