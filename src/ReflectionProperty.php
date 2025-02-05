<?php
/**
 * Parser Reflection API
 *
 * @copyright Copyright 2015-2022, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */
declare(strict_types=1);

namespace Go\ParserReflection;

use Error;
use Go\ParserReflection\Traits\CanHoldAttributesTrait;
use Go\ParserReflection\Traits\InitializationTrait;
use Go\ParserReflection\Traits\InternalPropertiesEmulationTrait;
use Go\ParserReflection\ValueResolver\NodeExpressionResolver;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\PropertyProperty;
use Reflection as BaseReflection;
use ReflectionProperty as BaseReflectionProperty;

/**
 * AST-based reflection for class property
 *
 * @template T of object
 */
class ReflectionProperty extends BaseReflectionProperty
{
    use InitializationTrait;
    use InternalPropertiesEmulationTrait;
    use CanHoldAttributesTrait;

    /**
     * Type of property node
     *
     * @var Property|null
     */
    private ?Property $propertyTypeNode;

    /**
     * Concrete property node
     *
     * @var PropertyProperty|null
     */
    private ?PropertyProperty $propertyNode;

    /**
     * Property promotion constructor parameter
     *
     * @var Param|null
     */
    private ?Param $paramNode;

    /**
     * Name of the class
     *
     * @var string
     */
    private string $className;

    /**
     * Initializes a reflection for the property
     *
     * @param string            $className    Name of the class with properties
     * @param string            $propertyName Name of the property to reflect
     * @param ?Property         $propertyType Property type definition node
     * @param ?PropertyProperty $propertyNode Concrete property definition (value, name)
     * @param ?Param            $paramNode    Property promotion constructor parameter
     *
     * @throws ReflectionException
     *
     * @noinspection PhpMissingParentConstructorInspection
     */
    public function __construct(
        string $className,
        string $propertyName,
        Property $propertyType = null,
        PropertyProperty $propertyNode = null,
        Param $paramNode = null
    ) {
        $this->className = ltrim($className, '\\');

        if (!($propertyType && $propertyNode) && !$paramNode) {
            [$propertyType, $propertyNode, $paramNode] = ReflectionEngine::parseClassProperty(
                $className, $propertyName
            );
        }

        $this->propertyTypeNode = $propertyType;
        $this->propertyNode     = $propertyNode;
        $this->paramNode        = $paramNode;

        // Let's unset original read-only properties to have a control over them via __get
        unset($this->name, $this->class);
    }

    /**
     * Return string representation of this little old property.
     *
     * @return string
     */
    public function __toString(): string
    {
        $modifiers = implode(' ', BaseReflection::getModifierNames($this->getModifiers()));
        $name      = $this->getName();
        $default   = null;
        if ($this->isDefault()) {
            $quoteString = null;
            $checkType   = true;

            $default          = $this->getDefaultValue();
            $defaultValueType = gettype($default);
            $defaultNode      = $this->getNode()->default;
            $declaringClass   = $this->getDeclaringClass();
            $namespaceName    = $declaringClass->getNamespaceName();

            // PHP >= 8.1 has changed the way how default values are represented
            if (PHP_VERSION_ID >= 80100) {
                // TODO: Outside constants are printed as "self::CONSTANT"

                // Constants are displayed with namespace prefix and without quotes
                if ($defaultNode instanceof ConstFetch) {
                    if ($namespaceName) {
                        $namespaceName .= '\\';
                    }

                    $default   = $namespaceName . $defaultNode->name->toString();
                    $checkType = false;
                }

                // Class constants are escaped with backslashes
                if ($defaultNode instanceof ClassConstFetch
                    || $defaultNode instanceof MagicConst
                ) {
                    // __CLASS__ inside a trait returns __CLASS__
                    // TODO: Not declaring class, but the class where the trait is used
                    if ($declaringClass->isTrait()
                        && $defaultNode instanceof MagicConst\Class_
                    ) {
                        $default = '__CLASS__';
                        $quoteString = false;
                    }

                    // Escape backslashes in strings
                    else if ($defaultValueType === 'string') {
                        $default = str_replace('\\', '\\\\', $default);
                        $quoteString = true;
                    }
                }
            }

            // Resolve by type
            if ($checkType) {
                switch ($defaultValueType) {
                    // String
                    case 'string':
                        /** @var string $default */
                        // PHP >= 8.1 shows the full string
                        // PHP < 8.1 truncates the string
                        if (PHP_VERSION_ID < 80100) {
                            // If longer than 15 characters, truncate it
                            if (strlen($default) > 15) {
                                $default = substr($default, 0, 15) . '...';
                            }
                        }

                        if ($quoteString === null) $quoteString = true;
                        break;

                    case 'boolean':
                        /** @var boolean $default */
                        $default = $default ? 'true' : 'false';
                        break;

                    case 'double':
                        /** @var double $default */
                        // PHP >= 8.1 shows the full float
                        // PHP < 8.1 truncates the float
                        if (PHP_VERSION_ID >= 80100) {
                            $afterPoint = strlen(substr((string) strrchr((string) $default, "."), 1));
                            $default = (string) round($default, 15);
                            if (!str_contains((string) $default, '.')) {
                                $default .= '.' . $afterPoint;
                            }
                        }
                        break;

                    // Array
                    case 'array':
                        /** @var array $default */
                        // PHP >= 8.1 shows the full array
                        if (PHP_VERSION_ID >= 80100) {
                            // TODO: Show the full array
                            // TODO: Outside constants inside arrays replaces the array with "self::CONSTANT"

                            $default = '[]';
                        }

                        // PHP < 8.1 shows "Array"
                        else {
                            $default = 'Array';
                        }
                        break;

                    // NULL
                    case 'NULL':
                        /** @var null $default */
                        $default = 'NULL';
                        break;
                }
            }

            if ($quoteString) {
                $default = sprintf("'%s'", $default);
            }
        }

        // Add assignment sign if there is a default value
        $default = $default ? sprintf('= %s', $default) : '';

        return sprintf(
            "Property [ %s $%s %s ]\n",
            $modifiers,
            $name,
            $default,
        );
    }

    /**
     * Returns an AST-node for property
     *
     * @return PropertyProperty|Param
     */
    public function getNode(): PropertyProperty|Param
    {
        if ($this->isPromoted()) {
            return $this->paramNode;
        } else {
            return $this->propertyNode;
        }
    }

    /**
     * Returns an AST-node for property type
     *
     * @return Property
     */
    public function getTypeNode(): Property
    {
        return $this->propertyTypeNode;
    }

    /**
     * Emulating original behaviour of reflection.
     *
     * Called when invoking {@link var_dump()} on an object
     *
     * @return array{name: string, class: class-string}
     */
    public function __debugInfo(): array
    {
        try {
            if ($this->propertyNode) {
                $name = $this->propertyNode->name->toString();
            } else {
                $paramName = $this->paramNode->var->name;
                if ($paramName instanceof Identifier) {
                    $name = $paramName->toString();
                } else {
                    $name = $paramName;
                }
            }
            $class = $this->className;
        } catch (Error) {
            // If we are here, then we are in the middle of the object creation
            $name  = null;
            $class = null;
        }

        return [
            'name'  => $name,
            'class' => $class,
        ];
    }

    /**
     * Gets declaring class
     *
     * @link https://php.net/manual/en/reflectionproperty.getdeclaringclass.php
     *
     * @return ReflectionClass A {@see ReflectionClass} object.
     *
     * @noinspection PhpDocMissingThrowsInspection
     */
    public function getDeclaringClass(): ReflectionClass
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        return new ReflectionClass($this->className);
    }

    /**
     * {@inheritDoc}
     */
    public function getDocComment(): string|false
    {
        if ($this->isPromoted()) {
            $docBlock = $this->paramNode->getDocComment();
        } else {
            $docBlock = $this->propertyTypeNode->getDocComment();
        }

        return $docBlock ? $docBlock->getText() : false;
    }

    /**
     * {@inheritDoc}
     */
    public function getModifiers(): int
    {
        $modifiers = 0;
        if ($this->isPublic()) {
            $modifiers += self::IS_PUBLIC;
        }
        if ($this->isProtected()) {
            $modifiers += self::IS_PROTECTED;
        }
        if ($this->isPrivate()) {
            $modifiers += self::IS_PRIVATE;
        }
        if ($this->isStatic()) {
            $modifiers += self::IS_STATIC;
        }

        return $modifiers;
    }

    /**
     * Gets property name
     *
     * @link https://php.net/manual/en/reflectionproperty.getname.php
     *
     * @return string The name of the reflected property.
     */
    public function getName(): string
    {
        if ($this->isPromoted()) {
            $name = $this->paramNode->var->name;
            return $name instanceof Expr
                ? $name->name->toString()
                : $name;
        } else {
            return $this->propertyNode->name->toString();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getValue(?object $object = null): mixed
    {
        if (!isset($object)) {
            $solver = new NodeExpressionResolver($this->getDeclaringClass());
            if (!isset($this->propertyNode->default)) {
                return null;
            }
            $solver->process($this->propertyNode->default);

            return $solver->getValue();
        }

        $this->initializeInternalReflection();

        return parent::getValue($object);
    }

    /**
     * {@inheritDoc}
     */
    public function isDefault(): bool
    {
        $this->initializeInternalReflection();

        return parent::isDefault();
    }

    /**
     * {@inheritDoc}
     */
    public function getDefaultValue(): mixed
    {
        $this->initializeInternalReflection();

        return parent::getDefaultValue();
    }

    /**
     * {@inheritDoc}
     */
    public function isPrivate(): bool
    {
        return $this->isPromoted()
            ? ($this->paramNode->flags & Class_::MODIFIER_PRIVATE) > 0
            : $this->propertyTypeNode->isPrivate();
    }

    /**
     * {@inheritDoc}
     */
    public function isProtected(): bool
    {
        return $this->isPromoted()
            ? ($this->paramNode->flags & Class_::MODIFIER_PROTECTED) > 0
            : $this->propertyTypeNode->isProtected();
    }

    /**
     * {@inheritDoc}
     */
    public function isPublic(): bool
    {
        return $this->isPromoted()
            ? ($this->paramNode->flags & Class_::MODIFIER_PUBLIC) > 0
            : $this->propertyTypeNode->isPublic();
    }

    /**
     * {@inheritDoc}
     */
    public function isStatic(): bool
    {
        return $this->isPromoted()
            ? ($this->paramNode->flags & Class_::MODIFIER_STATIC) > 0
            : $this->propertyTypeNode->isStatic();
    }

    /**
     * {@inheritDoc}
     */
    public function setAccessible(bool $accessible): void
    {
        $this->initializeInternalReflection();

        parent::setAccessible($accessible);
    }

    /**
     * {@inheritDoc}
     */
    public function setValue(mixed $objectOrValue, mixed $value = null): void
    {
        $this->initializeInternalReflection();

        parent::setValue($objectOrValue, $value);
    }

    /**
     * Returns information about whether the property was promoted.
     *
     * @return bool Returns {@see true} if the property was promoted or {@see false} instead.
     */
    public function isPromoted(): bool
    {
        return $this->paramNode !== null;
    }

    /**
     * Returns an array of property attributes.
     *
     * @template T
     *
     * @param class-string<T>|null $name  Name of an attribute class
     * @param int                  $flags Criteria by which the attribute is searched.
     *
     * @return ReflectionAttribute<T>[]
     */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        if (!isset($this->attributes)) {
            $this->collectAttributes();
        }

        return $this->attributes;
    }

    /**
     * Parses properties from the concrete class node
     *
     * @param ClassLike $classLikeNode Class-like node
     * @param string    $fullClassName FQN of the class
     *
     * @return array|ReflectionProperty[]
     */
    public static function collectFromClassNode(ClassLike $classLikeNode, string $fullClassName): array
    {
        $properties = [];

        foreach ($classLikeNode->stmts as $classLevelNode) {
            if ($classLevelNode instanceof Property) {
                foreach ($classLevelNode->props as $classPropertyNode) {
                    $propertyName = $classPropertyNode->name->toString();
                    try {
                        $properties[$propertyName] = new static(
                            $fullClassName,
                            $propertyName,
                            $classLevelNode,
                            $classPropertyNode
                        );
                    } catch (ReflectionException) {
                        // Ignore properties that cannot be parsed
                    }
                }
            }

            // Also collect properties from constructor with property promotion
            elseif ($classLevelNode instanceof ClassMethod
                && $classLevelNode->name->toString() === '__construct'
            ) {
                foreach ($classLevelNode->getParams() as $param) {
                    if ($param->flags !== 0) {
                        $propertyName = $param->var->name;

                        try {
                            $properties[$propertyName] = new static(
                                $fullClassName,
                                $propertyName,
                                paramNode: $param
                            );
                        } catch (ReflectionException) {
                            // Ignore promoted properties that cannot be parsed
                        }
                    }
                }
            }
        }

        return $properties;
    }

    /**
     * Implementation of internal reflection initialization
     *
     * @return void
     *
     * @noinspection PhpDocMissingThrowsInspection
     */
    protected function __initialize(): void
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        parent::__construct($this->className, $this->getName());
    }
}
