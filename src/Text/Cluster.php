<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Text;

/**
 * pdfplumber's cluster_objects: groups items whose keys are chained within a tolerance.
 */
final class Cluster
{
    /**
     * @template T
     *
     * @param list<T> $items
     * @param callable(T): float $key
     * @param bool $preserveOrder keep the input order and split where the cluster changes
     *
     * @return list<list<T>>
     */
    public static function objects(array $items, callable $key, float $tolerance, bool $preserveOrder = false): array
    {
        if ($items === []) {
            return [];
        }
        $values = [];
        foreach ($items as $i => $item) {
            $values[$i] = (float) $key($item);
        }

        // Cluster numbers follow the sorted values; equal values share a cluster.
        $sorted = $values;
        asort($sorted);
        $clusters = [];
        $cluster = -1;
        $last = null;
        foreach ($sorted as $i => $value) {
            if ($last === null) {
                $cluster = 0;
            } elseif ($tolerance == 0) {
                if ($value !== $last) {
                    $cluster++;
                }
            } elseif ($value > $last + $tolerance) {
                $cluster++;
            }
            $clusters[$i] = $cluster;
            $last = $value;
        }

        $groups = [];
        if ($preserveOrder) {
            $current = null;
            foreach ($items as $i => $item) {
                if ($clusters[$i] !== $current) {
                    $groups[] = [];
                    $current = $clusters[$i];
                }
                $groups[count($groups) - 1][] = $item;
            }

            return $groups;
        }
        $byCluster = [];
        foreach ($items as $i => $item) {
            $byCluster[$clusters[$i]][] = $item;
        }
        ksort($byCluster);

        return array_values($byCluster);
    }
}
