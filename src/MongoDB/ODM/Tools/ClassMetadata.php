<?php

namespace JPC\MongoDB\ODM\Tools;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Cache\ApcuCache;
use JPC\MongoDB\ODM\Tools\ClassMetadata\CollectionInfo;
use JPC\MongoDB\ODM\Tools\ClassMetadata\FieldInfo;

class ClassMetadata {
    /* ================================== */
    /*              CONSTANTS             */
    /* ================================== */

    const CLASS_ANNOT = '$CLASS';
    const PROPERTIES_ANNOT = '$PROPETIES';

    /**
     * Salt for caching
     * @var     string
     */
    private $cacheSalt = '$ANNOTATIONS';

    /**
     * Annotation Reader
     * @var     AnnotationReader 
     */
    private $reader;

    /**
     * Class name
     * @var     string
     */
    private $name;

    /**
     * Show if all class metadas ar loaded
     * @var bool 
     */
    private $loaded = false;

    /**
     * Info about the collection
     * @var CollectionInfos
     */
    private $collectionInfo;

    /**
     * Infos about fields/propeties;
     * @var type 
     */
    private $propertiesInfos = [];

    /**
     * Create new ClassMetadata
     * 
     * @param   string              $className          Name of the class
     * @param   AnnotationReader    $reader             Annotation reader
     */
    public function __construct($className, $reader) {
        $this->reader = $reader;

        $this->name = $className;
        $this->annotationCache = new ApcuCache();
    }
    
    public function getName(){
        return $this->name;
    }

    public function getCollection() {
        if (!$this->loaded) {
            $this->load();
        }

        return $this->collectionInfo->getCollection();
    }
    
    public function getPropertyInfo($prop){
         if (!$this->loaded) {
            $this->load();
        }
        
        if(isset($this->propertiesInfos[$prop])){
            return $this->propertiesInfos[$prop];
        }

        return false;
    }
    
    public function getPropertiesInfos(){
        if (!$this->loaded) {
            $this->load();
        }
        
        return $this->propertiesInfos;
    }
    
    public function getPropertyInfoForField($field){
        foreach ($this->propertiesInfos as $name => $infos) {
            if($infos->getField() == $field){
                return $infos;
            }
        }
        
        return false;
    }
    
    public function getPropertyForField($field){
        foreach ($this->propertiesInfos as $name => $infos) {
            if($infos->getField() == $field){
                return new \ReflectionProperty($this->name, $name);
            }
        }
        
        return false;
    }
    
    public function setCollection($collection){
        if (!$this->loaded) {
            $this->load();
        }

        $this->collectionInfo->setCollection($collection);
        return $this;
    }
    
    public function getRepositoryClass(){
        if (!$this->loaded) {
            $this->load();
        }

        return $this->collectionInfo->getRepository();
    }

    private function load() {
        $reflectionClass = new \ReflectionClass($this->name);
        $this->collectionInfo = new CollectionInfo();

        foreach ($this->reader->getClassAnnotations($reflectionClass) as $annotation) {
            $this->processClassAnnotation($annotation);
        }

        $properties = $reflectionClass->getProperties();

        foreach ($properties as $property) {
            $this->propertiesInfos[$property->getName()] = new FieldInfo();
            foreach ($this->reader->getPropertyAnnotations($property) as $annotation) {
                $this->processPropertiesAnnotation($property->getName(), $annotation);
            }
        }
        
        $this->loaded = true;
    }

    private function processClassAnnotation($annotation) {
        $class = get_class($annotation);
        switch ($class) {
            case "JPC\MongoDB\ODM\Annotations\Mapping\Document" :
                $this->collectionInfo->setCollection($annotation->collectionName);

                if (null !== ($rep = $annotation->repositoryClass)) {
                    $this->collectionInfo->setRepository($annotation->repositoryClass);
                } else {
                    $this->collectionInfo->setRepository("JPC\MongoDB\ODM\Repository");
                }
                break;
            case "JPC\MongoDB\ODM\Annotations\GridFS\Document" :
                $this->collectionInfo->setCollection($annotation->bucketName);

                if (null !== ($rep = $annotation->repositoryClass)) {
                    $this->collectionInfo->setRepository($annotation->repositoryClass);
                } else {
                    $this->collectionInfo->setRepository("JPC\MongoDB\ODM\GridFS\Repository");
                }
                break;
            case "JPC\MongoDB\ODM\Annotations\Mapping\Option":
                break;
        }
    }

    private function processPropertiesAnnotation($name, $annotation) {
        $class = get_class($annotation);
        switch ($class) {
            case "JPC\MongoDB\ODM\Annotations\Mapping\Id" :
                $this->propertiesInfos[$name]->setField("_id");
                break;
            case "JPC\MongoDB\ODM\Annotations\Mapping\Field" :
                $this->propertiesInfos[$name]->setField($annotation->name);
                break;
            case "JPC\MongoDB\ODM\Annotations\Mapping\EmbeddedDocument" :
                $this->propertiesInfos[$name]->setEmbedded(true);
                $this->propertiesInfos[$name]->setEmbeddedClass($annotation->document);
                break;
            case "JPC\MongoDB\ODM\Annotations\Mapping\MultiEmbeddedDocument" :
                $this->propertiesInfos[$name]->setMultiEmbedded(true);
                $this->propertiesInfos[$name]->setEmbeddedClass($annotation->document);
                break;
            case "JPC\MongoDB\ODM\Annotations\Mapping\Id" :
                $this->propertiesInfos[$name]->setField("_id");
                break;
            case "JPC\MongoDB\ODM\Annotations\GridFS\Stream" :
                $this->propertiesInfos[$name]->setField("stream");
                break;
            case "JPC\MongoDB\ODM\Annotations\GridFS\Filename" :
                $this->propertiesInfos[$name]->setField("filename");
                break;
            case "JPC\MongoDB\ODM\Annotations\GridFS\Aliases" :
                $this->propertiesInfos[$name]->setField("aliases");
                break;
            case "JPC\MongoDB\ODM\Annotations\GridFS\ChunkSize" :
                $this->propertiesInfos[$name]->setField("chunkSize");
                break;
            case "JPC\MongoDB\ODM\Annotations\GridFS\UploadDate" :
                $this->propertiesInfos[$name]->setField("uploadDate");
                break;
            case "JPC\MongoDB\ODM\Annotations\GridFS\Length" :
                $this->propertiesInfos[$name]->setField("length");
                break;
            case "JPC\MongoDB\ODM\Annotations\GridFS\ContentType" :
                $this->propertiesInfos[$name]->setField("contentType");
                break;
            case "JPC\MongoDB\ODM\Annotations\GridFS\Md5" :
                $this->propertiesInfos[$name]->setField("md5");
                break;
            case "JPC\MongoDB\ODM\Annotations\GridFS\Metadata" :
                $this->propertiesInfos[$name]->setMetadata(true);
                break;
        }
    }

}
