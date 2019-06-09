<?php

class Xhgui_Storage_File implements Xhgui_StorageInterface, Xhgui_WatchedFunctionsStorageInterface
{

    /**
     * @var string
     */
    protected $path     = '../data/';

    /**
     * @var string
     */
    protected $prefix   = 'xhgui.data';

    /**
     * @var bool|mixed
     */
    protected $separateMeta = true;

    /**
     * @var mixed
     */
    protected $dataSerializer;

    /**
     * @var mixed
     */
    protected $metaSerializer;

    /**
     * @var string
     */
    protected $watchedFunctionsPathPrefix = '../watched_functions/';

    /**
     * @var int[]
     */
    protected $countCache;

    /**
     * @var Xhgui_Storage_Filter
     */
    private $filter;

    /**
     * Xhgui_Storage_File constructor.
     * @param $config
     */
    public function __construct($config)
    {

        // @todo config!
        $this->path         = '../data/';

        // @todo config!
        $this->prefix       = 'xhgui.data';

        $this->separateMeta     = $config['save.handler.separate_meta'];
        $this->dataSerializer   = $config['save.handler.serializer'];
        $this->metaSerializer   = $config['save.handler.meta_serializer'];
    }

    /**
     * @param Xhgui_Storage_Filter $filter
     * @param bool $projections
     * @return Xhgui_Storage_ResultSet
     */
    public function find(Xhgui_Storage_Filter $filter, $projections = false)
    {
        $result       = glob($this->path. $this->prefix . '*');
        sort($result);

        $ret = [];
        foreach($result as $i => $file) {
            // skip meta files.
            if (strpos($file, '.meta') !== false) {
                continue;
            }

            // try to detect timestamp in filename.
            $requestTimeFromFilename = $this->getRequestTimeFromFilename($file);
            if (!empty($requestTimeFromFilename)) {
                if (null !== $filter->getStartDate() && $this->getDateTimeFromStringOrTimestamp($filter->getStartDate()) >= $requestTimeFromFilename) {
                    continue;
                }

                if (null !== $filter->getEndDate() && $this->getDateTimeFromStringOrTimestamp($filter->getEndDate()) <= $requestTimeFromFilename) {
                    continue;
                }
            }

            $metaFile   = $this->getMetafileNameFromProfileName($file);

            $meta       = $this->importFile($metaFile, true);
            if ($meta === false) {
                continue;
            }

            $profile    = $this->importFile($file, false);
            if ($profile === false) {
                continue;
            }

            if (!empty($profile['meta'])) {
                $meta = array_merge($meta, $profile['meta']);
            }

            if (!empty($profile['profile'])) {
                $profile = $profile['profile'];
            }

            if (!empty($profile['_id'])) {
                $id = $profile['_id'];
            } else {
                $id = basename($file);
            }
            if (!empty($profile)) {
                $ret[$id] = [
                    'profile'   => $profile,
                    '_id'       => $id,
                    'meta'      => $meta,
                ];
            } else {
                $ret[$id] = $profile;
            }
        }

        try {
            if (!empty($filter->getSort()) AND !empty($ret)) {
                $this->filter = $filter;
                usort($ret, array($this, 'sortByColumn'));
                unset($this->filter);
            }
        }catch (InvalidArgumentException $e) {
            
        }
        $cacheId = md5(serialize($filter->toArray()));

        $this->countCache[$cacheId] = count($ret);
        $ret = array_slice($ret, $filter->getPerPage()*($filter->getPage()-1), $filter->getPerPage());
        $ret = array_column($ret, null, '_id');

        return new Xhgui_Storage_ResultSet($ret, $this->countCache[$cacheId]);
    }

    /**
     * @param Xhgui_Storage_Filter $filter
     * @return int
     */
    public function count(Xhgui_Storage_Filter $filter)
    {
        $cacheId = md5(serialize($filter->toArray()));
        if (empty($this->countCache[$cacheId])) {
            $this->find($filter);
        }
        return $this->countCache[$cacheId];
    }

    /**
     * @param $id
     * @return mixed
     */
    public function findOne($id)
    {
        $filter = new Xhgui_Storage_Filter();
        $filter->setId($id);
        $resultSet = $this->find($id);
        return $resultSet->current();
    }

    /**
     * @param $id
     * @return bool
     */
    public function remove($id)
    {
        if (file_exists($this->path.$id)) {
            $metaFileName = $this->getMetafileNameFromProfileName($id);
            if (file_exists($this->path.$metaFileName)) {
                unlink($this->path.$metaFileName);
            }
            unlink($this->path.$id);
            return true;
        }
        return false;
    }

    /**
     *
     */
    public function drop()
    {
        array_map('unlink', glob($this->path.'*.xhprof'));
        array_map('unlink', glob($this->path.'*.meta'));
    }

    /**
     * @param $match
     * @param $col
     * @param int $percentile
     * @return array
     */
    public function aggregate(Xhgui_Storage_Filter $filter, $col, $percentile = 1)
    {
        $ret = $this->find($filter);

        $result = [
            'ok'        => 1,
            'result'    => [],
        ];

        foreach($ret as $row) {
            $result['result'][$row['meta']['request_date']]['wall_times'][]    = $row['profile']['main()']['wt'];
            $result['result'][$row['meta']['request_date']]['cpu_times'][]     = $row['profile']['main()']['cpu'];
            $result['result'][$row['meta']['request_date']]['mu_times'][]      = $row['profile']['main()']['mu'];
            $result['result'][$row['meta']['request_date']]['pmu_times'][]     = $row['profile']['main()']['pmu'];

            if (empty($result['result'][$row['meta']['request_date']]['row_count'])) {
                $result['result'][$row['meta']['request_date']]['row_count'] = 0;
            }
            $result['result'][$row['meta']['request_date']]['row_count']++;

            $result['result'][$row['meta']['request_date']]['raw_index'] = $result['result'][$row['meta']['request_date']]['row_count']*($percentile/100);

            $result['result'][$row['meta']['request_date']]['_id']=$row['meta']['request_date'];
        }

        return $result;
    }


    /**
     * @param $a
     * @param $b
     * @return int
     */
    public function sortByColumn($a, $b)
    {
        $sort = $this->filter->getSort();
        switch($sort) {
            case 'ct':
            case 'wt':
            case 'cpu':
            case 'mu':
            case 'pmu':
                $aValue = $a['profile']['main()'][$sort];
                $bValue = $b['profile']['main()'][$sort];
                break;

            case 'time':
                $aValue = $a['meta']['request_ts']['sec'];
                $bValue = $b['meta']['request_ts']['sec'];
                break;

            case 'controller':
            case 'action':
            case 'application':
            case 'branch':
            case 'version':
                $aValue = $a['meta'][$sort];
                $bValue = $b['meta'][$sort];
                break;

            default:
                throw new InvalidArgumentException('Invalid sort mode');
                break;
        }

        if ($aValue == $bValue){
            return 0;
        }

        if (is_numeric($aValue) || is_numeric($bValue)) {
            if ($this->filter->getDirection() === 'desc') {
                if ($aValue < $bValue) {
                    return 1;
                }
                return -1;
            }

            if ($aValue > $bValue) {
                return 1;
            }
            return -1;
        }

        if ($this->filter->getDirection() === 'desc') {
            return strnatcmp($aValue, $bValue);
        }
        return strnatcmp($bValue, $aValue);
    }



    /**
     * @param $file
     * @return mixed
     */
    protected function getMetafileNameFromProfileName($file)
    {
        $metaFile = $file.'.meta';
        return $metaFile;
    }


    /**
     * @param $timestamp
     * @return bool|DateTime
     */
    protected function getDateTimeFromStringOrTimestamp($timestamp)
    {

        try {
            $date = new DateTime($timestamp);
            return $date;
        } catch(Exception $e) {
            // leave empty to try parse different format below
        }

        try {
            $date = DateTime::createFromFormat('U', $timestamp);
            return $date;
        } catch(Exception $e) {
            // leave empty to try parse different format below
        }

        try {
            $date = DateTime::createFromFormat('Y-m-d H:i:s', $timestamp);
            return $date;
        } catch(Exception $e) {
            // last attempt failed. Throw generic exception.
            throw new RuntimeException('Unable to parse date from string: '.$timestamp, null, $e);
        }
    }

    /**
     * @param $data
     */
    public function insert(array $data)
    {

        if (empty($data['_id'])) {
            $data['_id'] = md5($data['name']);
        }

        file_put_contents($this->path.''.$this->prefix.$data['_id'].'.json', json_encode($data));
    }

    /**
     * @param $id
     * @param $data
     */
    public function update($id, array $data)
    {
        file_put_contents($this->path.''.$this->prefix.$id.'.json', json_encode($data));
    }

    /**
     * @param $path
     * @param bool $meta
     * @return mixed
     */
    protected function importFile($path, $meta = false)
    {
        if ($meta) {
            $serializer = $this->metaSerializer;
        } else {
            $serializer = $this->dataSerializer;
        }

        if (!file_exists($path) || !is_readable($path)) {
            return false;
        }
        
        switch ($serializer){
            default:
            case 'json':
                return json_decode(file_get_contents($path), true);

            case 'serialize':
                if (PHP_MAJOR_VERSION > 7) {
                    return unserialize(file_get_contents($path), false);
                }
                /** @noinspection UnserializeExploitsInspection */
                return unserialize(file_get_contents($path));

            case 'igbinary_serialize':
            case 'igbinary_unserialize':
            case 'igbinary':
                /** @noinspection PhpComposerExtensionStubsInspection */
                return igbinary_unserialize(file_get_contents($path));

            // this is a path to a file on disk
            case 'php':
            case 'var_export':
                /** @noinspection PhpIncludeInspection */
                return include $path;
        }
    }

    /**
     * @return array
     */
    public function getWatchedFunctions()
    {
        $ret = [];
        $files = glob($this->watchedFunctionsPathPrefix.'*.json');
        foreach ($files as $file) {
            $ret[] = json_decode(file_get_contents($file));
        }
        return $ret;
    }

    /**
     * @param $name
     * @return bool
     */
    public function addWatchedFunction($name)
    {
        $name = trim($name);
        if (empty($name)) {
            return false;
        }
        $id = md5($name);
        $i = file_put_contents($this->watchedFunctionsPathPrefix.$id.'.json', json_encode(['id'=>$id, 'name'=>$name]));
        return $i > 0;
    }

    /**
     * @param $id
     * @param $name
     * @return bool
     */
    public function updateWatchedFunction($id, $name)
    {
        $name = trim($name);
        if (empty($name)) {
            return false;
        }

        $i = file_put_contents($this->watchedFunctionsPathPrefix.$id.'.json', json_encode(['id'=>$id, 'name'=>trim($name)]));
        return $i > 0;
    }

    /**
     * @param $id
     */
    public function removeWatchedFunction($id)
    {
        if (file_exists($this->watchedFunctionsPathPrefix.$id.'.json')) {
            unlink($this->watchedFunctionsPathPrefix.$id.'.json');
        }
    }

    /**
     * @param $fileName
     * @return bool|DateTime
     */
    public function getRequestTimeFromFilename($fileName)
    {
        $matches = [];
        // default pattern is: xhgui.data.<timestamp>.<microseconds>_a68888
        //  xhgui.data.15 55 31 04 66 .6606_a68888
        preg_match('/(?<t>[\d]{10})(\.(?<m>[\d]{1,6}))?.+/i', $fileName, $matches);
        try {
            return DateTime::createFromFormat('U u', $matches['t'].' '. $matches['m']);
        } catch (Exception $e) {
            return null;
        }
    }
}