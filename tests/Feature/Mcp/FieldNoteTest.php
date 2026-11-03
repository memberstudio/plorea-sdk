<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature\Mcp;

use MemberFlow\Plorea\Exceptions\ChargeFailedException;
use MemberFlow\Plorea\Mcp\PloreaServer;
use MemberFlow\Plorea\Mcp\Tools\FieldNote;

class FieldNoteTest extends McpTestCase
{
    public function test_a_status_code_finds_its_note(): void
    {
        $result = $this->callTool(FieldNote::class, ['query' => '403']);

        $this->assertSame('A 401 or 403 that is not about the API key', $result['notes'][0]['title']);
    }

    public function test_a_fully_qualified_exception_class_finds_its_note(): void
    {
        $result = $this->callTool(FieldNote::class, ['query' => ChargeFailedException::class]);

        $this->assertSame('400 and 402 on a subscription charge mean different things', $result['notes'][0]['title']);
    }

    public function test_exact_trigger_matches_come_before_partial_ones(): void
    {
        $result = $this->callTool(FieldNote::class, ['query' => 'payment_failed']);

        $this->assertSame('After the last retry: payment_failed, not canceled', $result['notes'][0]['title']);
    }

    public function test_the_refund_note_covers_subscription_charge_refunds(): void
    {
        $note = $this->callTool(FieldNote::class, ['query' => 'refund_requested'])['notes'][0]['note'];

        $this->assertStringContainsString('Subscription charges are different', $note);
        $this->assertStringContainsString('Never cancel a charged subscription charge', $note);
    }

    public function test_an_unknown_query_lists_the_topics(): void
    {
        $result = $this->callTool(FieldNote::class, ['query' => 'nothing-like-this']);

        $this->assertSame([], $result['notes']);
        $this->assertCount(count(FieldNote::notes()), $result['topics']);
    }

    public function test_an_empty_query_is_an_error(): void
    {
        PloreaServer::tool(FieldNote::class, ['query' => ' '])->assertHasErrors();
    }

    public function test_every_note_is_complete_and_unique(): void
    {
        $notes = FieldNote::notes();
        $ids = array_column($notes, 'id');

        $this->assertSame($ids, array_unique($ids));

        foreach ($notes as $note) {
            foreach (['title', 'evidence', 'note'] as $field) {
                $this->assertNotSame('', trim($note[$field]), "{$note['id']} has no {$field}.");
            }

            $this->assertNotSame([], $note['triggers'], "{$note['id']} has no triggers.");
        }
    }

    public function test_every_note_points_at_a_heading_that_exists(): void
    {
        foreach (FieldNote::notes() as $note) {
            $path = __DIR__.'/../../../'.$note['source']['file'];

            $this->assertFileExists($path, "{$note['id']} points at a missing file.");

            $headings = array_map(
                fn (string $line): string => trim(ltrim($line, '#')),
                preg_grep('/^#{1,4} /', (array) file($path, FILE_IGNORE_NEW_LINES)) ?: [],
            );

            $this->assertContains($note['source']['heading'], $headings, "{$note['id']} points at a heading that no longer exists.");
        }
    }
}
