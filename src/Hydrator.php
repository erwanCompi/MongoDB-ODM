<?php

namespace JPC\MongoDB\ODM;

use JPC\MongoDB\ODM\DocumentManager;
use JPC\MongoDB\ODM\Factory\ClassMetadataFactory;
use JPC\MongoDB\ODM\Factory\RepositoryFactory;
use JPC\MongoDB\ODM\Tools\ClassMetadata\ClassMetadata;

/**
 * Hydrate and unhydrate object from/to array data
 */
class Hydrator
{

    /**
     * Document Manager
     * @var DocumentManager
     */
    protected $classMetadataFactory;

    /**
     * Class metadatas
     * @var ClassMetadata
     */
    protected $classMetadata;

    /**
     * Document Manager
     * @var DocumentManager
     */
    protected $documentManager;

    /**
     * Repository factory (Used for referenced fields)
     * @var RepositoryFactory
     */
    protected $repositoryFactory;

    /**
     * Create hydrator
     *
     * @param ClassMetadataFactory  $classMetadataFactory   Class metadata factory
     * @param ClassMetadata         $classMetadata          Metadata of class will be hydrated
     * @param DocumentManager       $documentManager        Document manager of class to hydrate
     * @param RepositoryFactory     $repositoryFactory      Repository factory
     */
    public function __construct(ClassMetadataFactory $classMetadataFactory, ClassMetadata $classMetadata, DocumentManager $documentManager, RepositoryFactory $repositoryFactory)
    {
        $this->classMetadataFactory = $classMetadataFactory;
        $this->classMetadata = $classMetadata;
        $this->documentManager = $documentManager;
        $this->repositoryFactory = $repositoryFactory;
    }

    /**
     * Hydrate an object
     *
     * @param   mixed   $object             Object to hydrate
     * @param   array   $data               Data wich will hydrate object
     * @param   integer $maxReferenceDepth  Max reference depth
     * @return  void
     */
    public function hydrate(&$object, $data, $soft = false, $maxReferenceDepth = 10)
    {
        $reflectionClass = new \ReflectionClass($this->classMetadata->getName());
        if ($data instanceof \MongoDB\Model\BSONArray || $data instanceof \MongoDB\Model\BSONDocument) {
            $data = (array) $data;
        }
        if (!is_array($data)) {
            throw new \Exception('$data must be an array');
        }
        $properties = $this->classMetadata->getPropertiesInfos();

        foreach ($properties as $name => $infos) {
            $setter = 'set' . ucfirst($name);
            if (!$reflectionClass->hasMethod($setter)) {
                $prop = new \ReflectionProperty($this->classMetadata->getName(), $name);
                $prop->setAccessible(true);
            } else {
                $prop = null;
            }

            if (null !== ($field = $infos->getField())) {
                if (!$soft && !array_key_exists($field, $data)) {
                    $this->setValue($object, null, $setter, $prop);
                } elseif (array_key_exists($field, $data)) {
                    if (!$soft && $data[$field] == null) {
                        continue;
                    }
                    if ((($data[$field] instanceof \MongoDB\Model\BSONDocument) || is_array($data[$field])) && $infos->getEmbedded() && null !== ($class = $infos->getEmbeddedClass())) {
                        if ($infos->isDiscriminable()) {
                            if (null !== ($discrimatorField = $infos->getDiscriminatorField())
                                && null !== ($discrimatorMap = $infos->getDiscriminatorMap())) {
                                $discrimatorValue = $data[$field][$discrimatorField] ?? null;
                                if (isset($discrimatorValue)) {
                                    $class = $discrimatorMap[$discrimatorValue] ?? $class;
                                }
                            } else {
                                //call_s
                            }
                        }
                        if (!class_exists($class)) {
                            $class = $this->classMetadata->getNamespace() . "\\" . $class;
                        }
                        $embedded = new $class();
                        $this->getHydrator($class)->hydrate($embedded, $data[$field]);
                        $data[$field] = $embedded;
                    }

                    if ((($data[$field] instanceof \MongoDB\Model\BSONArray) || ($data[$field] instanceof \MongoDB\Model\BSONDocument) || is_array($data[$field])) && $infos->getMultiEmbedded() && null !== ($class = $infos->getEmbeddedClass())) {
                        $array = [];
                        $originalClass = $class;
                        foreach ($data[$field] as $key => $value) {
                            if ($infos->isDiscriminable()) {
                                if (null !== ($discrimatorField = $infos->getDiscriminatorField())
                                    && null !== ($discrimatorMap = $infos->getDiscriminatorMap())) {
                                    $discrimatorValue = $data[$field][$key][$discrimatorField] ?? null;
                                    if (isset($discrimatorValue)) {
                                        $class = $discrimatorMap[$discrimatorValue] ?? $originalClass;
                                    }
                                } else {
                                    //call_s
                                }
                            }
                            if (!class_exists($class)) {
                                $class = $this->classMetadata->getNamespace() . "\\" . $class;
                            }
                            if ($value === null) {
                                continue;
                            }

                            $embedded = new $class();
                            $this->getHydrator($class)->hydrate($embedded, $value);
                            $array[$key] = $embedded;
                        }
                        $data[$field] = $array;
                    }

                    if (null !== ($refInfos = $infos->getReferenceInfo()) && !$refInfos->getIsMultiple() && $maxReferenceDepth > 0) {
                        $repository = $this->repositoryFactory->getRepository($this->documentManager, $refInfos->getDocument(), $refInfos->getCollection());

                        $objectDatas = $repository->getCollection()->findOne(["_id" => $data[$field]]);
                        $referedObject = null;

                        if (isset($objectDatas)) {
                            $class = $refInfos->getDocument();

                            if (!class_exists($class)) {
                                $class = $this->classMetadata->getNamespace() . "\\" . $class;
                            }

                            $referedObject = new $class();

                            $hydrator = $repository->getHydrator();
                            $hydrator->hydrate($referedObject, $objectDatas, $soft, $maxReferenceDepth - 1);
                        }
                        $data[$field] = $referedObject;
                    }

                    if (null !== ($refInfos = $infos->getReferenceInfo()) && $refInfos->getIsMultiple()) {
                        $repository = $this->repositoryFactory->getRepository($this->documentManager, $refInfos->getDocument(), $refInfos->getCollection());

                        if (!$data[$field] instanceof \MongoDB\Model\BSONArray && !is_array($data[$field])) {
                            throw new \Exception("RefersMany value must be an array for document with '_id' : " . $data["_id"]);
                        } else {
                            $objectsDatas = $repository->getCollection()->find(["_id" => ['$in' => $data[$field]]]);
                        }

                        $objectArray = null;

                        if (!empty($objectsDatas)) {
                            $objectArray = [];
                            foreach ($objectsDatas as $objectDatas) {
                                $class = $refInfos->getDocument();

                                if (!class_exists($class)) {
                                    $class = $this->classMetadata->getNamespace() . "\\" . $class;
                                }

                                $referedObject = new $class();

                                $hydrator = $repository->getHydrator();
                                $hydrator->hydrate($referedObject, $objectDatas, $soft, $maxReferenceDepth - 1);

                                $objectArray[] = $referedObject;
                            }
                        }
                        $data[$field] = $objectArray;
                    }

                    $this->setValue($object, $data[$field], $setter, $prop);
                }
            }
        }
    }

    /**
     * Unhydrate an object
     *
     * @param   object  $object     Object to unhydrate
     * @return  array               Unhydrated Object
     */
    public function unhydrate($object)
    {
        $properties = $this->classMetadata->getPropertiesInfos();
        $datas = [];

        if (!is_object($object)) {
            return $object;
        }

        foreach ($properties as $name => $infos) {
            $prop = new \ReflectionProperty($this->classMetadata->getName(), $name);
            $prop->setAccessible(true);

            $value = $prop->getValue($object);

            if (null === $value) {
                continue;
            }

            if (($value instanceof \MongoDB\Model\BSONDocument) || ($value instanceof \MongoDB\Model\BSONArray)) {
                $value = $this->recursiveConvertInArray((array) $value);
            }

            if (is_object($value) && $infos->getEmbedded()) {
                $class = get_class($value);
                if (!class_exists($class)) {
                    $class = $this->classMetadata->getNamespace() . "\\" . $class;
                }
                $value = $this->getHydrator($class)->unhydrate($value);
            }

            if (is_array($value) && $infos->getMultiEmbedded()) {
                $array = [];
                foreach ($value as $key => $embeddedValue) {
                    $class = get_class($embeddedValue);
                    if (!class_exists($class)) {
                        $class = $this->classMetadata->getNamespace() . "\\" . $class;
                    }
                    $array[$key] = $this->getHydrator($class)->unhydrate($embeddedValue);
                }
                $value = $array;
            }

            if (is_object($value) && null != ($refInfos = $infos->getReferenceInfo()) && !$refInfos->getIsMultiple() && !$value instanceof \MongoDB\BSON\ObjectId) {
                $class = $refInfos->getDocument();
                if (!class_exists($class)) {
                    $class = $this->classMetadata->getNamespace() . "\\" . $class;
                }

                $value = $this->getHydrator($class)->unhydrate($value)["_id"];
            }

            if (is_array($value) && null != ($refInfos = $infos->getReferenceInfo()) && $refInfos->getIsMultiple()) {
                $array = [];
                foreach ($value as $referedValue) {
                    $class = $refInfos->getDocument();
                    if (!class_exists($class)) {
                        $class = $this->classMetadata->getNamespace() . "\\" . $class;
                    }
                    if (!$value instanceof \MongoDB\BSON\ObjectId) {
                        $array[] = $this->getHydrator($class)->unhydrate($referedValue)["_id"];
                    } else {
                        $array[] = $value;
                    }
                }
                $value = $array;
            }

            if ($value instanceof \DateTime) {
                $value = new \MongoDB\BSON\UTCDateTime($value->getTimestamp() * 1000);
            }

            $datas[$infos->getField()] = $value;
        }

        return $datas;
    }

    /**
     * Convert BSONDocument and BSONArray to array recursively
     *
     * @param   array   $array  Array to convert
     * @return  array
     */
    public function recursiveConvertInArray($array)
    {
        $newArray = [];
        foreach ($array as $key => $value) {
            if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
                $value = (array) $value;
            }

            if (is_array($value)) {
                $value = $this->recursiveConvertInArray($value);
            }

            $newArray[$key] = $value;
        }

        return $newArray;
    }

    /**
     * Get hydrator for specified class
     *
     * @param   string              $class              Class which you will get hydrator
     * @return  Hydrator                                Hydrator corresponding to specified class
     */
    public function getHydrator($class)
    {
        $metadata = $this->classMetadataFactory->getMetadataForClass($class);
        return new Hydrator($this->classMetadataFactory, $metadata, $this->documentManager, $this->repositoryFactory);
    }

    private function setValue($object, $value, $setter, \ReflectionProperty $prop = null)
    {
        if (isset($prop)) {
            $prop->setValue($object, $value);
        } else {
            $object->$setter($value);
        }
    }
}
