<?php

namespace App\Http\ApiDocs;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;

/**
 * Documents the gym's {organization} slug in the API docs' paths.
 *
 * The organization middleware reads the slug and then removes it from the
 * route (ADRs 0004 and 0010), so no controller method takes it. Scramble
 * matches path parameters to method arguments by position, so it gave the
 * slug the name of the next plain int or string argument and lost the real
 * one, as in /me/gyms/{notification}/notifications/{notification}/read.
 * This keeps the route's own names, in the route's order, and the types
 * Scramble found for them. See ADR 0021.
 */
class DocumentTheGymInThePath implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $route = $routeInfo->route;
        $routeParameterNames = $route->parameterNames();

        if (! in_array('organization', $routeParameterNames, true)) {
            return;
        }

        $operation->setPath(Str::replace('?}', '}', $route->uri()));

        $documentedPathParameters = collect($operation->parameters)
            ->filter(fn (mixed $parameter): bool => $parameter instanceof Parameter && $parameter->in === 'path');

        $pathParameters = collect($routeParameterNames)
            ->map(fn (string $name): Parameter => $name === 'organization'
                ? $this->gymSlug()
                : $documentedPathParameters->first(fn (Parameter $parameter): bool => $parameter->name === $name)
                    ?? $this->plainPathParameter($name));

        $otherParameters = collect($operation->parameters)
            ->reject(fn (mixed $parameter): bool => $parameter instanceof Parameter && $parameter->in === 'path');

        $operation->parameters = [...$pathParameters->all(), ...$otherParameters->all()];
    }

    private function gymSlug(): Parameter
    {
        return $this->plainPathParameter('organization')
            ->description('The gym\'s slug, such as `demo-gym`.');
    }

    private function plainPathParameter(string $name): Parameter
    {
        return Parameter::make($name, 'path')
            ->required(true)
            ->setSchema(Schema::fromType(new StringType));
    }
}
