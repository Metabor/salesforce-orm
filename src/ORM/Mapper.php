<?php
namespace Salesforce\ORM;

use Salesforce\ORM\Exception\MapperException;
use Salesforce\ORM\Annotation\Field;
use Salesforce\ORM\Annotation\Object;
use Salesforce\ORM\Annotation\Required;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use ReflectionClass;

class Mapper
{
    /** @var AnnotationReader */
    protected $reader;

    /**
     * Mapper constructor.
     *
     * @param AnnotationReader|null $reader reader
     * @throws \Doctrine\Common\Annotations\AnnotationException
     */
    public function __construct(AnnotationReader $reader = null)
    {
        $this->reader = $reader ?: new AnnotationReader();
    }

    /**
     * @param Entity $entity entity
     * @return string
     * @throws \Salesforce\ORM\Exception\MapperException
     */
    public function getObjectType(Entity $entity)
    {
        $reflectionClass = $this->reflect($entity);
        /* @var Object $object */
        $object = $this->reader->getClassAnnotation($reflectionClass, Object::class);
        if (!$object->name) {
            throw new MapperException(MapperException::OBJECT_TYPE_NOT_FOUND . get_class($entity));
        }

        return $object->name;
    }

    /**
     * Patch object properties with data array
     *
     * @param Entity $entity entity
     * @param array $array array
     * @return Entity
     * @throws \Salesforce\ORM\Exception\MapperException
     */
    public function patch(Entity $entity, $array = [])
    {
        $reflectionClass = $this->reflect($entity);
        $properties = $reflectionClass->getProperties();

        $eagerLoad = [];
        $requiredProperties = [];
        foreach ($properties as $property) {
            $annotations = $this->reader->getPropertyAnnotations($property);
            foreach ($annotations as $annotation) {
                if ($annotation instanceof Field) {
                    if (isset($array[$annotation->name])) {
                        $this->setPropertyValue($entity, $property, $array[$annotation->name]);
                    }
                }

                if ($annotation instanceof RelationInterface) {
                    if ($annotation->lazy === false) {
                        $eagerLoad[$property->name] = ['property' => $property, 'relation' => $annotation];
                    }
                }

                if ($annotation instanceof Required) {
                    if ($annotation->value === true) {
                        $requiredProperties[$property->name] = $property;
                    }
                }
            }
        }

        if (!empty($eagerLoad)) {
            $this->setPropertyValueByName($entity, Entity::PROPERTY_EAGER_LOAD, $eagerLoad);
        }

        if (!empty($requiredProperties)) {
            $this->setPropertyValueByName($entity, Entity::PROPERTY_REQUIRED_PROPERTIES, $requiredProperties);
        }

        $this->setPropertyValueByName($entity, Entity::PROPERTY_IS_PATCHED, true);

        return $entity;
    }

    /**
     * @param Entity $entity entity
     * @return bool|array
     * @throws \Salesforce\ORM\Exception\MapperException
     */
    public function checkRequiredProperties(Entity $entity)
    {
        if ($entity->isPatched() !== true) {
            $entity = $this->patch($entity, []);
        }

        if (empty($entity->getRequiredProperties())) {
            return true;
        }

        $missingFields = [];
        /* @var \ReflectionProperty $property */
        foreach ($entity->getRequiredProperties() as $property) {
            if ($this->getPropertyValue($entity, $property) === null) {
                $missingFields[] = $property->name;
            };
        }

        if (empty($missingFields)) {
            return true;
        }

        return $missingFields;
    }

    /**
     * @param Entity $entity entity
     * @return array
     * @throws \Salesforce\ORM\Exception\MapperException
     */
    public function toArray(Entity $entity)
    {
        $reflectionClass = $this->reflect($entity);
        $properties = $reflectionClass->getProperties();

        $array = [];
        foreach ($properties as $property) {
            $annotations = $this->reader->getPropertyAnnotations($property);
            foreach ($annotations as $annotation) {
                if ($annotation instanceof Field) {
                    $array[$annotation->name] = $this->getPropertyValue($entity, $property);
                }
            }
        }

        return $array;
    }

    /**
     * Set property value
     *
     * @param Entity $entity entity
     * @param \ReflectionProperty $property property
     * @param mixed $value value
     * @return void
     */
    public function setPropertyValue(Entity &$entity, \ReflectionProperty $property, $value)
    {
        $property->setAccessible(true);
        $property->setValue($entity, $value);
    }

    /**
     * Get property value
     *
     * @param Entity $entity entity
     * @param \ReflectionProperty $property property
     * @return mixed
     */
    public function getPropertyValue(Entity $entity, \ReflectionProperty $property)
    {
        if ($property instanceof \ReflectionProperty) {
            $property->setAccessible(true);

            return $property->getValue($entity);
        }
    }

    /**
     * Set property value by name
     *
     * @param Entity $entity entity
     * @param string $propertyName name
     * @param mixed $value value
     * @return void
     * @throws \Salesforce\ORM\Exception\MapperException
     */
    public function setPropertyValueByName(Entity &$entity, string $propertyName, $value)
    {
        $property = $this->getProperty($entity, $propertyName);
        $this->setPropertyValue($entity, $property, $value);
    }

    /**
     * Get property value by name
     *
     * @param Entity $entity entity
     * @param string $propertyName name
     * @return mixed
     * @throws \Salesforce\ORM\Exception\MapperException
     */
    public function getPropertyValueByName(Entity $entity, string $propertyName)
    {
        $property = $this->getProperty($entity, $propertyName);

        return $this->getPropertyValue($entity, $property);
    }

    /**
     * @param Entity $entity entity
     * @param string $propertyName name
     * @return bool|\ReflectionProperty
     * @throws \Salesforce\ORM\Exception\MapperException
     */
    public function getProperty(Entity $entity, string $propertyName)
    {
        $reflectionClass = $this->reflect($entity);
        $properties = $reflectionClass->getProperties();
        /* @var \ReflectionProperty $property */
        foreach ($properties as $property) {
            if ($property->name == $propertyName) {
                return $property;
            }
        }

        return false;
    }

    /**
     * @param Entity $entity entity
     * @return ReflectionClass
     * @throws \Salesforce\ORM\Exception\MapperException
     */
    protected function reflect(Entity $entity)
    {
        try {
            $reflectionClass = new ReflectionClass(get_class($entity));
            $this->register();
        } catch (\ReflectionException $exception) {
            throw new MapperException(MapperException::FAILED_TO_CREATE_REFLECT_CLASS . $exception->getMessage());
        }

        return $reflectionClass;
    }

    /**
     * Register annotation classes
     *
     * @return void
     */
    protected function register()
    {
        if (class_exists(AnnotationRegistry::class)) {
            AnnotationRegistry::registerLoader('class_exists');
        }
    }
}