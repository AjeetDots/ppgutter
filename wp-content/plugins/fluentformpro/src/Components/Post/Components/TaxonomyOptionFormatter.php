<?php

namespace FluentFormPro\Components\Post\Components;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class TaxonomyOptionFormatter
{
    public static function formatOptions($terms)
    {
        $formattedTerms = self::flattenTerms($terms);
        $options = [];

        foreach ($formattedTerms as $item) {
            $term = $item['term'];
            $depth = $item['depth'];

            $options[] = [
                'value' => $term->term_id,
                'label' => self::formatLabel($term->name, $depth),
            ];
        }

        return $options;
    }

    public static function formatLabels($terms)
    {
        $labels = [];

        foreach (self::formatOptions($terms) as $option) {
            $labels[$option['value']] = $option['label'];
        }

        return $labels;
    }

    protected static function flattenTerms($terms, $parentId = 0, $depth = 0, &$visited = [])
    {
        $groupedTerms = self::groupTermsByParent($terms);
        $flattened = self::flattenGroupedTerms($groupedTerms, $parentId, $depth, $visited);

        if ($parentId !== 0) {
            return $flattened;
        }

        foreach ($groupedTerms as $groupedParentId => $parentTerms) {
            if ($groupedParentId === 0) {
                continue;
            }

            foreach ($parentTerms as $term) {
                if (!isset($visited[$term->term_id])) {
                    $flattened[] = [
                        'term'  => $term,
                        'depth' => 0,
                    ];

                    $visited[$term->term_id] = true;

                    $flattened = array_merge(
                        $flattened,
                        self::flattenGroupedTerms($groupedTerms, $term->term_id, 1, $visited)
                    );
                }
            }
        }

        return $flattened;
    }

    protected static function flattenGroupedTerms($groupedTerms, $parentId, $depth, &$visited)
    {
        $flattened = [];

        foreach ($groupedTerms[$parentId] ?? [] as $term) {
            if (isset($visited[$term->term_id])) {
                continue;
            }

            $visited[$term->term_id] = true;
            $flattened[] = [
                'term'  => $term,
                'depth' => $depth,
            ];

            $flattened = array_merge(
                $flattened,
                self::flattenGroupedTerms($groupedTerms, $term->term_id, $depth + 1, $visited)
            );
        }

        return $flattened;
    }

    protected static function groupTermsByParent($terms)
    {
        $groupedTerms = [];

        foreach ($terms as $term) {
            $parentId = (int) $term->parent;

            if (!isset($groupedTerms[$parentId])) {
                $groupedTerms[$parentId] = [];
            }

            $groupedTerms[$parentId][] = $term;
        }

        return $groupedTerms;
    }

    protected static function formatLabel($label, $depth)
    {
        if (!$depth) {
            return $label;
        }

        return str_repeat('- ', $depth) . $label;
    }
}
