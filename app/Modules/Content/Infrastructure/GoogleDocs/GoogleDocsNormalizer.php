<?php

namespace App\Modules\Content\Infrastructure\GoogleDocs;

final class GoogleDocsNormalizer
{
    /** @param array<string, mixed> $document @return array<string, mixed> */
    public function normalize(array $document): array
    {
        $blocks = [];
        $content = $document['body']['content'] ?? [];

        if (is_array($content)) {
            foreach ($content as $node) {
                if (! is_array($node)) {
                    continue;
                }

                if (is_array($node['paragraph'] ?? null)) {
                    $blocks[] = $this->paragraph($node['paragraph']);
                    continue;
                }

                if (is_array($node['table'] ?? null)) {
                    $blocks[] = $this->table($node['table']);
                }
            }
        }

        return [
            'schema' => 'google-docs-normalized-v1',
            'title' => trim((string) ($document['title'] ?? '')),
            'blocks' => array_values(array_filter($blocks, fn (array $block): bool => $block['type'] !== 'paragraph' || $block['text'] !== '')),
        ];
    }

    /** @param array<string, mixed> $paragraph @return array<string, mixed> */
    private function paragraph(array $paragraph): array
    {
        $runs = [];
        $text = '';

        foreach (($paragraph['elements'] ?? []) as $element) {
            if (! is_array($element)) {
                continue;
            }

            $textRun = $element['textRun'] ?? null;
            if (! is_array($textRun)) {
                continue;
            }

            $value = str_replace("\r", '', (string) ($textRun['content'] ?? ''));
            $text .= $value;
            $style = is_array($textRun['textStyle'] ?? null) ? $textRun['textStyle'] : [];
            $runs[] = [
                'text' => rtrim($value, "\n"),
                'bold' => (bool) ($style['bold'] ?? false),
                'italic' => (bool) ($style['italic'] ?? false),
                'underline' => (bool) ($style['underline'] ?? false),
                'link' => is_array($style['link'] ?? null) ? ($style['link']['url'] ?? null) : null,
            ];
        }

        $style = is_array($paragraph['paragraphStyle'] ?? null) ? $paragraph['paragraphStyle'] : [];
        $bullet = is_array($paragraph['bullet'] ?? null) ? $paragraph['bullet'] : null;

        return [
            'type' => 'paragraph',
            'style' => (string) ($style['namedStyleType'] ?? 'NORMAL_TEXT'),
            'text' => trim($text),
            'runs' => $runs,
            'bullet' => $bullet ? [
                'list_id' => (string) ($bullet['listId'] ?? ''),
                'nesting_level' => (int) ($bullet['nestingLevel'] ?? 0),
            ] : null,
        ];
    }

    /** @param array<string, mixed> $table @return array<string, mixed> */
    private function table(array $table): array
    {
        $rows = [];
        foreach (($table['tableRows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $cells = [];
            foreach (($row['tableCells'] ?? []) as $cell) {
                $parts = [];
                foreach (($cell['content'] ?? []) as $node) {
                    if (is_array($node) && is_array($node['paragraph'] ?? null)) {
                        $parts[] = $this->paragraph($node['paragraph'])['text'];
                    }
                }
                $cells[] = trim(implode("\n", array_filter($parts)));
            }
            $rows[] = $cells;
        }

        return ['type' => 'table', 'rows' => $rows];
    }
}
