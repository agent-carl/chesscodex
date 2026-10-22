<?php
declare(strict_types=1);

// PHPStan return types that differ from what PHP's own signatures promise here.
// A stub file cannot change these (PHPStan's built-in function map wins over it).
// Registered in phpstan.neon.

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

// The connection uses PDO::ERRMODE_EXCEPTION (lib/db.php): query() and prepare() throw instead of returning false.
final class PdoStatementReturnType implements DynamicMethodReturnTypeExtension
{
    public function getClass(): string { return PDO::class; }
    public function isMethodSupported(MethodReflection $methodReflection): bool { return in_array($methodReflection->getName(), ['query', 'prepare'], true); }
    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): Type
    {
        return new ObjectType(PDOStatement::class);
    }
}

// With ERRMODE_EXCEPTION fetchAll() always returns a list of rows.
final class PdoFetchAllReturnType implements DynamicMethodReturnTypeExtension
{
    public function getClass(): string { return PDOStatement::class; }
    public function isMethodSupported(MethodReflection $methodReflection): bool { return $methodReflection->getName() === 'fetchAll'; }
    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): Type
    {
        return TypeCombinator::intersect(new ArrayType(new IntegerType(), new MixedType()), new AccessoryArrayListType());
    }
}

// Every image in og.php comes from imagecreatetruecolor(), where allocation cannot fail.
final class ImageColorAllocateReturnType implements DynamicFunctionReturnTypeExtension
{
    public function isFunctionSupported(FunctionReflection $functionReflection): bool { return $functionReflection->getName() === 'imagecolorallocate'; }
    public function getTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, Scope $scope): Type
    {
        return new IntegerType();
    }
}

// Cache::remember() returns what a closure computed after a round trip through a JSON file:
// PHPStan cannot know its shape, so callers state it in their own PHPDoc (implicit mixed).
final class CacheRememberReturnType implements DynamicStaticMethodReturnTypeExtension
{
    public function getClass(): string { return Cache::class; }
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool { return $methodReflection->getName() === 'remember'; }
    public function getTypeFromStaticMethodCall(MethodReflection $methodReflection, StaticCall $methodCall, Scope $scope): Type
    {
        return new MixedType();
    }
}
