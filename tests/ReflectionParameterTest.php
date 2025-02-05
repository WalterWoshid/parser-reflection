<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace Go\ParserReflection;

use PHPUnit\Framework\TestCase;
use Go\ParserReflection\Stub\Foo;
use Go\ParserReflection\Stub\SubFoo;
use ReflectionClass as BaseReflectionClass;
use ReflectionParameter as BaseReflectionParameter;
use stdClass;
use TestParametersForRootNsClass;
use Traversable;

class ReflectionParameterTest extends TestCase
{
    /**
     * @var ReflectionFile
     */
    protected ReflectionFile $parsedRefFile;

    protected function setUp(): void
    {
        $this->setUpFile(__DIR__ . '/Stub/FileWithParameters55.php');
    }

    /**
     * @dataProvider fileProvider
     */
    public function testGeneralInfoGetters($fileName)
    {
        $this->setUpFile($fileName);
        $allNameGetters = [
            'isArray', 'isCallable', 'isOptional', 'isPassedByReference', 'isDefaultValueAvailable',
            'getPosition', 'canBePassedByValue', 'allowsNull', 'getDefaultValue', 'getDefaultValueConstantName',
            'isDefaultValueConstant', '__toString'
        ];
        $onlyWithDefaultValues = array_flip([
            'getDefaultValue', 'getDefaultValueConstantName', 'isDefaultValueConstant'
        ]);
        if (PHP_VERSION_ID >= 50600) {
            $allNameGetters[] = 'isVariadic';
        }
        if (PHP_VERSION_ID >= 70000) {
            $allNameGetters[] = 'hasType';
        }

        foreach ($this->parsedRefFile->getFileNamespaces() as $fileNamespace) {
            foreach ($fileNamespace->getFunctions() as $refFunction) {
                $functionName = $refFunction->getName();
                foreach ($refFunction->getParameters() as $refParameter) {
                    $parameterName        = $refParameter->getName();
                    $originalRefParameter = new BaseReflectionParameter($functionName, $parameterName);
                    foreach ($allNameGetters as $getterName) {

                        // skip some methods if there is no default value
                        $isDefaultValueAvailable = $originalRefParameter->isDefaultValueAvailable();
                        if (isset($onlyWithDefaultValues[$getterName]) && !$isDefaultValueAvailable) {
                            continue;
                        }
                        $expectedValue = $originalRefParameter->$getterName();
                        $actualValue   = $refParameter->$getterName();
                        $this->assertSame(
                            $expectedValue,
                            $actualValue,
                            "$getterName() for parameter $functionName:$parameterName should be equal"
                        );
                    }
                }
            }
        }
    }

    /**
     * Provides a list of files for analysis
     *
     * @return array
     */
    public function fileProvider(): array
    {
        $files = ['PHP5.5' => [__DIR__ . '/Stub/FileWithParameters55.php']];

        if (PHP_VERSION_ID >= 50600) {
            $files['PHP5.6'] = [__DIR__ . '/Stub/FileWithParameters56.php'];
        }
        if (PHP_VERSION_ID >= 70000) {
            $files['PHP7.0'] = [__DIR__ . '/Stub/FileWithParameters70.php'];
        }

        return $files;
    }

    public function testGetClassMethod()
    {
        $parsedNamespace = $this->parsedRefFile->getFileNamespace('Go\ParserReflection\Stub');
        $parsedFunction  = $parsedNamespace->getFunction('miscParameters');

        $parameters = $parsedFunction->getParameters();
        $this->assertSame(null, $parameters[0 /* array $arrayParam*/]->getClass());
        $this->assertSame(null, $parameters[3 /* callable $callableParam */]->getClass());

        $objectParam = $parameters[5 /* \stdClass $objectParam */]->getClass();
        $this->assertInstanceOf(BaseReflectionClass::class, $objectParam);
        $this->assertSame(stdClass::class, $objectParam->getName());

        $typehintedParamWithNs = $parameters[7 /* ReflectionParameter $typehintedParamWithNs */]->getClass();
        $this->assertInstanceOf(BaseReflectionClass::class, $typehintedParamWithNs);
        $this->assertSame(ReflectionParameter::class, $typehintedParamWithNs->getName());

        $internalInterfaceParam = $parameters[12 /* \Traversable $traversable */]->getClass();
        $this->assertInstanceOf(BaseReflectionClass::class, $internalInterfaceParam);
        $this->assertSame(Traversable::class, $internalInterfaceParam->getName());
    }

    public function testGetClassMethodReturnsSelfAndParent()
    {
        $parsedNamespace = $this->parsedRefFile->getFileNamespace('Go\ParserReflection\Stub');
        $parsedClass     = $parsedNamespace->getClass(SubFoo::class);
        $parsedFunction  = $parsedClass->getMethod('anotherMethodParam');

        $parameters = $parsedFunction->getParameters();
        $selfParam = $parameters[0 /* self $selfParam */]->getClass();
        $this->assertInstanceOf(BaseReflectionClass::class, $selfParam);
        $this->assertSame(SubFoo::class, $selfParam->getName());

        $parentParam = $parameters[1 /* parent $parentParam */]->getClass();
        $this->assertInstanceOf(BaseReflectionClass::class, $parentParam);
        $this->assertSame(Foo::class, $parentParam->getName());
    }

    public function testNonConstantsResolvedForGlobalNamespace()
    {
        $parsedNamespace = $this->parsedRefFile->getFileNamespace('');
        $parsedClass     = $parsedNamespace->getClass(TestParametersForRootNsClass::class);
        $parsedFunction  = $parsedClass->getMethod('foo');

        $parameters = $parsedFunction->getParameters();
        $this->assertSame(null, $parameters[0]->getDefaultValue());
        $this->assertSame(false, $parameters[1]->getDefaultValue());
        $this->assertSame(true, $parameters[2]->getDefaultValue());
    }

    public function testGetDeclaringClassMethodReturnsObject()
    {
        $parsedNamespace = $this->parsedRefFile->getFileNamespace('Go\ParserReflection\Stub');
        $parsedClass     = $parsedNamespace->getClass(Foo::class);
        $parsedFunction  = $parsedClass->getMethod('methodParam');

        $parameters = $parsedFunction->getParameters();
        $this->assertSame($parsedClass->getName(), $parameters[0]->getDeclaringClass()->getName());
    }

    public function testParamWithDefaultConstValue()
    {
        $parsedNamespace = $this->parsedRefFile->getFileNamespace('Go\ParserReflection\Stub');
        $parsedClass     = $parsedNamespace->getClass(Foo::class);
        $parsedFunction  = $parsedClass->getMethod('methodParamConst');

        $parameters = $parsedFunction->getParameters();
        $this->assertTrue($parameters[0]->isDefaultValueConstant());
        $this->assertSame('self::CLASS_CONST', $parameters[0]->getDefaultValueConstantName());

        $this->assertTrue($parameters[2]->isDefaultValueConstant());
        $this->assertSame('Go\ParserReflection\Stub\TEST_PARAMETER', $parameters[2]->getDefaultValueConstantName());

        $this->assertTrue($parameters[3]->isDefaultValueConstant());
        $this->assertSame('Go\ParserReflection\Stub\SubFoo::ANOTHER_CLASS_CONST', $parameters[3]->getDefaultValueConstantName());
    }

    public function testParamBuiltInClassConst()
    {
        $parsedNamespace = $this->parsedRefFile->getFileNamespace('Go\ParserReflection\Stub');
        $parsedClass     = $parsedNamespace->getClass(Foo::class);
        $parsedFunction  = $parsedClass->getMethod('methodParamBuiltInClassConst');

        $parameters = $parsedFunction->getParameters();
        $this->assertTrue($parameters[0]->isDefaultValueConstant());
        $this->assertSame('DateTime::ATOM', $parameters[0]->getDefaultValueConstantName());
    }

    public function testGetDeclaringClassMethodReturnsNull()
    {
        $parsedNamespace = $this->parsedRefFile->getFileNamespace('Go\ParserReflection\Stub');
        $parsedFunction  = $parsedNamespace->getFunction('miscParameters');

        $parameters = $parsedFunction->getParameters();
        $this->assertNull($parameters[0]->getDeclaringClass());
    }

    public function testDebugInfoMethod()
    {
        $parsedNamespace = $this->parsedRefFile->getFileNamespace('Go\ParserReflection\Stub');
        $parsedFunction  = $parsedNamespace->getFunction('miscParameters');

        $parsedRefParameters  = $parsedFunction->getParameters();
        $parsedRefParameter   = $parsedRefParameters[0];
        $originalRefParameter = new BaseReflectionParameter('Go\ParserReflection\Stub\miscParameters', 'arrayParam');
        $expectedValue        = (array) $originalRefParameter;
        $this->assertSame($expectedValue, $parsedRefParameter->__debugInfo());
    }

    /**
     * @dataProvider listOfDefaultGetters
     *
     * @param string $getterName Name of the getter to call
     */
    public function testGetDefaultValueThrowsAnException(string $getterName)
    {
        $originalException = null;
        $parsedException   = null;

        try {
            $originalRefParameter = new BaseReflectionParameter('Go\ParserReflection\Stub\miscParameters', 'arrayParam');
            $originalRefParameter->$getterName();
        } catch (\ReflectionException $e) {
            $originalException = $e;
        }

        try {
            $parsedNamespace = $this->parsedRefFile->getFileNamespace('Go\ParserReflection\Stub');
            $parsedFunction  = $parsedNamespace->getFunction('miscParameters');

            $parsedRefParameters  = $parsedFunction->getParameters();
            $parsedRefParameter   = $parsedRefParameters[0];
            $parsedRefParameter->$getterName();
        } catch (\ReflectionException $e) {
            $parsedException = $e;
        }

        $this->assertInstanceOf(\ReflectionException::class, $originalException);
        $this->assertInstanceOf(\ReflectionException::class, $parsedException);
        $this->assertSame($originalException->getMessage(), $parsedException->getMessage());
    }

    public function listOfDefaultGetters(): array
    {
        return [
            ['getDefaultValue'],
            ['getDefaultValueConstantName']
        ];
    }

    public function testCoverAllMethods()
    {
        $allInternalMethods = get_class_methods(BaseReflectionParameter::class);
        $allMissedMethods   = [];

        foreach ($allInternalMethods as $internalMethodName) {
            if ('export' === $internalMethodName) {
                continue;
            }
            $refMethod    = new \ReflectionMethod(ReflectionParameter::class, $internalMethodName);
            $definerClass = $refMethod->getDeclaringClass()->getName();
            if (!str_starts_with($definerClass, 'Go\\ParserReflection')) {
                $allMissedMethods[] = $internalMethodName;
            }
        }

        if ($allMissedMethods) {
            $this->markTestIncomplete('Methods ' . join(', ', $allMissedMethods) . ' are not implemented');
        } else {
            $this->assertTrue(true);
        }
    }

    public function testGetTypeMethod()
    {
        if (PHP_VERSION_ID < 70000) {
            $this->markTestSkipped('Test available only for PHP7.0 and newer');
        }
        $this->setUpFile(__DIR__ . '/Stub/FileWithParameters70.php');

        foreach ($this->parsedRefFile->getFileNamespaces() as $fileNamespace) {
            foreach ($fileNamespace->getFunctions() as $refFunction) {
                $functionName = $refFunction->getName();
                foreach ($refFunction->getParameters() as $refParameter) {
                    $parameterName        = $refParameter->getName();
                    $originalRefParameter = new BaseReflectionParameter($functionName, $parameterName);
                    $hasType              = $refParameter->hasType();
                    $this->assertSame(
                        $originalRefParameter->hasType(),
                        $hasType,
                        "Presence of type for parameter $functionName:$parameterName should be equal"
                    );
                    $message= "Parameter $functionName:$parameterName not equals to the original reflection";
                    if ($hasType) {
                        $parsedReturnType   = $refParameter->getType();
                        $originalReturnType = $originalRefParameter->getType();
                        $this->assertSame($originalReturnType->allowsNull(), $parsedReturnType->allowsNull(), $message);
                        $this->assertSame($originalReturnType->isBuiltin(), $parsedReturnType->isBuiltin(), $message);
                        // TODO: To prevent deprecation error in tests
                        if (PHP_VERSION_ID < 70400) {
                            $this->assertSame($originalReturnType->__toString(), $parsedReturnType->__toString(), $message);
                        } else {
                            $this->assertSame($originalReturnType->getName(), $parsedReturnType->__toString(), $message);
                        }
                    } else {
                        $this->assertSame(
                            $originalRefParameter->getType(),
                            $refParameter->getType(),
                            $message
                        );
                    }
                }
            }
        }
    }

    /**
     * Setups file for parsing
     *
     * @param string $fileName File name to use
     */
    private function setUpFile(string $fileName)
    {
        $fileName = stream_resolve_include_path($fileName);
        $fileNode = ReflectionEngine::parseFile($fileName);

        $reflectionFile = new ReflectionFile($fileName, $fileNode);
        $this->parsedRefFile = $reflectionFile;

        include_once $fileName;
    }
}
