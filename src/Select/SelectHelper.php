<?php

namespace Nuwave\Lighthouse\Select;

use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use Illuminate\Database\Eloquent\Model;
use Nuwave\Lighthouse\Schema\AST\ASTBuilder;
use Nuwave\Lighthouse\Schema\AST\ASTHelper;

class SelectHelper
{
    /**
     * Given a field definition node, resolve info, and a model name, return the SQL columns that should be selected.
     * Accounts for relationships and the rename and select directives.
     *
     * @param mixed[] $fieldSelection
     * @return string[]
     */
    public static function getSelectColumns(Node $definitionNode, array $fieldSelection, string $modelName): array
    {
        $returnTypeName = ASTHelper::getUnderlyingTypeName($definitionNode);

        /** @var \Nuwave\Lighthouse\Schema\AST\DocumentAST $documentAST */
        $documentAST = app(ASTBuilder::class)->documentAST();

        $type = $documentAST->types[$returnTypeName];
        // error_log(print_r($type, true));

        if ($type instanceof UnionTypeDefinitionNode) {
            $type = $documentAST->types[ASTHelper::getUnderlyingTypeName($type->types[0])];
        }


        /** @var iterable<\GraphQL\Language\AST\FieldDefinitionNode> $fieldDefinitions */
        $fieldDefinitions = $type->fields;

        $model = new $modelName;

        $selectColumns = [];

        foreach ($fieldSelection as $field) {
            $fieldDefinition = ASTHelper::firstByName($fieldDefinitions, $field);

            if ($fieldDefinition) {
                $directivesRequiringLocalKey = ['hasOne', 'hasMany', 'count'];
                $directivesRequiringForeignKey = ['belongsTo', 'belongsToMany', 'morphTo'];
                $directivesRequiringKeys = array_merge($directivesRequiringLocalKey, $directivesRequiringForeignKey);

                foreach ($directivesRequiringKeys as $directiveType) {
                    if (ASTHelper::hasDirective($fieldDefinition, $directiveType)) {
                        $directive = ASTHelper::directiveDefinition($fieldDefinition, $directiveType);

                        if (in_array($directiveType, $directivesRequiringLocalKey)) {
                            $relationName = ASTHelper::directiveArgValue($directive, 'relation', $field);

                            if (method_exists($model, $relationName)) {
                                array_push($selectColumns, $model->{$relationName}()->getLocalKeyName());
                            }
                        }

                        if (in_array($directiveType, $directivesRequiringForeignKey)) {
                            $relationName = ASTHelper::directiveArgValue($directive, 'relation', $field);

                            if (method_exists($model, $relationName)) {
                                array_push($selectColumns, $model->{$relationName}()->getForeignKeyName());
                            }
                        }

                        continue 2;
                    }
                }

                if (ASTHelper::hasDirective($fieldDefinition, 'select')) {
                    // append selected columns in select directive to seletion
                    $directive = ASTHelper::directiveDefinition($fieldDefinition, 'select');

                    if ($directive) {
                        $selectFields = ASTHelper::directiveArgValue($directive, 'columns') ?? [];
                        $selectColumns = array_merge($selectColumns, $selectFields);
                    }
                } elseif (ASTHelper::hasDirective($fieldDefinition, 'rename')) {
                    // append renamed attribute to selection
                    $directive = ASTHelper::directiveDefinition($fieldDefinition, 'rename');

                    if ($directive) {
                        $renamedAttribute = ASTHelper::directiveArgValue($directive, 'attribute');
                        array_push($selectColumns, $renamedAttribute);
                    }
                } else {
                    // fallback to selecting the field name
                    array_push($selectColumns, $field);
                }
            }
        }

        return array_unique($selectColumns);
    }
}