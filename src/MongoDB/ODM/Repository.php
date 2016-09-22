<?php

namespace JPC\MongoDB\ODM;

use JPC\MongoDB\ODM\DocumentManager;
use axelitus\Patterns\Creational\Multiton;
use JPC\MongoDB\ODM\ObjectManager;
use Doctrine\Common\Cache\ApcuCache;

/**
 * Allow to find, delete, document in MongoDB
 *
 * @author poree
 */
class Repository extends Multiton {

    /**
     * Hydrator of model
     * @var Hydrator
     */
    private $hydrator;

    /**
     *
     * @var \MongoDB\Collection
     */
    private $collection;

    /**
     * Object Manager
     * @var ObjectManager
     */
    private $om;

    /**
     * Cache for object changes
     * @var ApcuCache 
     */
    private $objectCache;
    private $arrayObjCache = [];

    public function __construct($classMetadata) {
        $this->modelName = $classMetadata->getName();

        $this->hydrator = Hydrator::instance($this->modelName, $classMetadata);

        $this->collection = DocumentManager::instance()->getMongoDBDatabase()->selectCollection($classMetadata->getClassAnnotation("JPC\MongoDB\ODM\Annotations\Mapping\Document")->collectionName);

        $this->om = ObjectManager::instance();

        $this->objectCache = new ApcuCache();
    }

    public function getHydrator() {
        return $this->hydrator;
    }

    public function count($filters = [], $options = []) {
        return $this->collection->count($filters, $options);
    }

    public function find($id, $projections = [], $options = []) {
        $result = $this->collection->findOne(["_id" => $id], $options);

        $object = new $this->modelName();
        $this->hydrator->hydrate($object, $result);

        $this->om->addObject($object, ObjectManager::OBJ_MANAGED);

        return $object;
    }

    public function findAll($projections = [], $sorts = [], $options = []) {
        $result = $this->collection->find([], $options);

        $objects = [];

        foreach ($result as $datas) {
            $object = new $this->modelName();
            $this->hydrator->hydrate($object, $datas);
            $objects[] = $object;

            $this->om->addObject($object, ObjectManager::OBJ_MANAGED);
        }

        return $objects;
    }

    public function findBy($filters, $projections = [], $sorts = [], $options = []) {
        $this->castAllQueries($filters, $projections, $sorts);

        $result = $this->collection->find($filters, $options);
        $objects = [];

        foreach ($result as $datas) {
            $object = new $this->modelName();
            $this->hydrator->hydrate($object, $datas);
            $objects[] = $object;

            $this->om->addObject($object, ObjectManager::OBJ_MANAGED);
        }

        return $objects;
    }

    public function findOneBy($filters = [], $projections = [], $sorts = [], $options = []) {
        $result = $this->collection->findOne($filters, $options);

        $object = new $this->modelName();

        $this->hydrator->hydrate($object, $result);

        $this->om->addObject($object, ObjectManager::OBJ_MANAGED);

        $this->cacheObject($object);

        return $object;
    }

    public function distinct($fieldName, $filters = [], $options = []) {
        $result = $this->collection->distinct($fieldName, $filters, $options);

        return $result;
    }

    public function drop() {
        $result = $this->collection->drop();

        if ($result->ok) {
            return true;
        } else {
            return false;
        }
    }

    private function castMongoQuery($query, $hydrator = null) {
        if (!isset($hydrator)) {
            $hydrator = $this->hydrator;
        }
        $new_query = [];
        foreach ($query as $name => $value) {
            $field = $hydrator->getFieldNameFor($name);
            if (is_array($value)) {
                $value = $this->castMongoQuery($value, $this->hydrator->getHydratorForField($field));
            }
            $new_query[$field] = $value;
        }
        return $new_query;
    }

    private function castAllQueries(&$filters, &$projection = [], &$sort = []) {
        $filters = $this->castMongoQuery($filters);
        $projection = $this->castMongoQuery($projection);
        $sort = $this->castMongoQuery($sort);
    }

    private function cacheObject($object) {
        $this->objectCache->save(spl_object_hash($object), json_encode($this->hydrator->unhydrate($object)), 120);
    }

    private function uncacheObject($object) {
        return json_decode($this->objectCache->fetch(spl_object_hash($object)));
    }

    public function getObjectChanges($object) {
        $new_datas = $this->hydrator->unhydrate($object);
        $old_datas = $this->uncacheObject($object);

        $changes = $this->compareDatas($new_datas, $old_datas);

        return $changes;
    }

    public function compareDatas($new, $old) {
        $changes = [];
        foreach ($new as $key => $value) {
            if (is_array($value)) {
                $compare = true;
                if (is_int(key($value))) {
                    $diff = array_diff_key($value, $old[$key]);
                    if (!empty($diff)) {
                        foreach ($diff as $diffKey => $diffValue) {
                            $changes[$key]['$push'][$diffKey] = $diffValue;
                        }
                        $compare = false;
                    }
                }

                if ($compare) {
                    $array_changes = $this->compareDatas($value, $old[$key]);
                    if (!empty($array_changes)) {
                        $changes[$key] = $array_changes;
                    }
                }
            } else if (isset($old[$key]) && $value != $old[$key]) {
                $changes[$key] = $value;
            }
        }

        return $changes;
    }

    public function compareValues($a, $b) {
        dump($a, $b);
    }

    /**
     * 
     * @return \MongoDB\Collection
     */
    public function getCollection() {
        return $this->collection;
    }

}
