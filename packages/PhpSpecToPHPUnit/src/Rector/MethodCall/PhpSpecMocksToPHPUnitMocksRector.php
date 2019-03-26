<?php declare(strict_types=1);

namespace Rector\PhpSpecToPHPUnit\Rector\MethodCall;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use Rector\Exception\ShouldNotHappenException;
use Rector\NodeTypeResolver\Node\Attribute;
use Rector\PhpSpecToPHPUnit\PhpSpecMockCollector;
use Rector\PhpSpecToPHPUnit\Rector\AbstractPhpSpecToPHPUnitRector;

final class PhpSpecMocksToPHPUnitMocksRector extends AbstractPhpSpecToPHPUnitRector
{
    /**
     * @var PhpSpecMockCollector
     */
    private $phpSpecMockCollector;

    public function __construct(PhpSpecMockCollector $phpSpecMockCollector)
    {
        $this->phpSpecMockCollector = $phpSpecMockCollector;
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class, MethodCall::class];
    }

    /**
     * @param ClassMethod|MethodCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isInPhpSpecBehavior($node)) {
            return null;
        }

        if ($node instanceof ClassMethod) {
            // public = tests, protected = internal, private = own (no framework magic)
            if ($node->isPrivate()) {
                return null;
            }

            $this->processMethodParamsToMocks($node);

            return $node;
        }

        return $this->processMethodCall($node);
    }

    /**
     * Variable or property fetch, based on number of present params in whole class
     */
    private function createCreateMockCall(Param $param, Name $name): ?Expression
    {
        /** @var Class_ $classNode */
        $classNode = $param->getAttribute(Attribute::CLASS_NODE);

        $classMocks = $this->phpSpecMockCollector->resolveClassMocksFromParam($classNode);

        $variable = $this->getName($param->var);
        $method = $param->getAttribute(Attribute::METHOD_NAME);

        $methodsWithWThisMock = $classMocks[$variable];

        // single use: "$mock = $this->createMock()"
        if (! $this->phpSpecMockCollector->isVariableMockInProperty($param->var)) {
            return $this->createNewMockVariableAssign($param, $name);
        }

        $reversedMethodsWithThisMock = array_flip($methodsWithWThisMock);

        // first use of many: "$this->mock = $this->createMock()"
        if ($reversedMethodsWithThisMock[$method] === 0) {
            return $this->createPropertyFetchMockVariableAssign($param, $name);
        }

        return null;
    }

    private function createMockVarDoc(Param $param, Name $name): string
    {
        $paramType = (string) ($name->getAttribute('originalName') ?: $name);
        $variableName = $this->getName($param->var);

        if ($variableName === null) {
            throw new ShouldNotHappenException();
        }

        return sprintf(
            '/** @var %s|\%s $%s */',
            $paramType,
            'PHPUnit\Framework\MockObject\MockObject',
            $variableName
        );
    }

    private function processMethodParamsToMocks(ClassMethod $classMethod): void
    {
        // remove params and turn them to instances
        $assigns = [];
        foreach ((array) $classMethod->params as $param) {
            if (! $param->type instanceof Name) {
                throw new ShouldNotHappenException();
            }

            $createMockCall = $this->createCreateMockCall($param, $param->type);
            if ($createMockCall) {
                $assigns[] = $createMockCall;
            }
        }

        // remove all params
        $classMethod->params = [];

        $classMethod->stmts = array_merge($assigns, (array) $classMethod->stmts);
    }

    private function processMethodCall(MethodCall $methodCall): ?MethodCall
    {
        if ($this->isName($methodCall, 'shouldBeCalled')) {
            if (! $methodCall->var instanceof MethodCall) {
                throw new ShouldNotHappenException();
            }

            $mockMethodName = $this->getName($methodCall->var);
            if ($mockMethodName === null) {
                throw new ShouldNotHappenException();
            }

            $expectedArg = $methodCall->var->args[0]->value ?? null;

            $methodCall->var->name = new Identifier('expects');
            $thisOnceMethodCall = new MethodCall(new Variable('this'), new Identifier('atLeastOnce'));
            $methodCall->var->args = [new Arg($thisOnceMethodCall)];

            $methodCall->name = new Identifier('method');
            $methodCall->args = [new Arg(new String_($mockMethodName))];

            if ($expectedArg) {
                return $this->appendWithMethodCall($methodCall, $expectedArg);
            }

            return $methodCall;
        }

        return null;
    }

    private function appendWithMethodCall(MethodCall $methodCall, Expr $expr): MethodCall
    {
        $withMethodCall = new MethodCall($methodCall, 'with');

        if ($expr instanceof StaticCall) {
            if ($this->isName($expr->class, '*Argument')) {
                if ($this->isName($expr->name, 'any')) {
                    // no added value having this method
                    return $methodCall;
                }

                if ($this->isName($expr->name, 'type')) {
                    $expr = new MethodCall(new Variable('this'), 'isType', $expr->args);
                }
            }
        }

        $withMethodCall->args = [new Arg($expr)];

        return $withMethodCall;
    }

    private function createNewMockVariableAssign(Param $param, Name $name): Expression
    {
        $methodCall = new MethodCall(new Variable('this'), 'createMock');
        $methodCall->args[] = new Arg(new ClassConstFetch($name, 'class'));

        $assign = new Assign($param->var, $methodCall);
        $assignExpression = new Expression($assign);

        // add @var doc comment
        $varDoc = $this->createMockVarDoc($param, $name);
        $assignExpression->setDocComment(new Doc($varDoc));

        return $assignExpression;
    }

    private function createPropertyFetchMockVariableAssign(Param $param, Name $name): Expression
    {
        $variable = $this->getName($param->var);
        if ($variable === null) {
            throw new ShouldNotHappenException();
        }

        $propertyFetch = new PropertyFetch(new Variable('this'), $variable);

        $methodCall = new MethodCall(new Variable('this'), 'createMock');
        $methodCall->args[] = new Arg(new ClassConstFetch($name, 'class'));

        $assign = new Assign($propertyFetch, $methodCall);

        return new Expression($assign);
    }
}
