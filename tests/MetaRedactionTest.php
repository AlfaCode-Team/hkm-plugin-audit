<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Audit\Application\Services\AuditService;

/**
 * Regression cover for D-01: the "never log secrets" rule was documented but
 * unenforced, so anything a caller put in $meta was persisted verbatim to both
 * the SIEM line and the audit_log row.
 */
#[CoversClass(AuditService::class)]
final class MetaRedactionTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    private function service(): AuditService
    {
        return new AuditService(sink: function (string $line): void {
            $this->lines[] = $line;
        });
    }

    private function recordMeta(array $meta): string
    {
        $this->lines = [];
        $this->service()->record('test.action', meta: $meta);

        return $this->lines[0] ?? '';
    }

    public function test_secret_valued_keys_are_redacted(): void
    {
        $line = $this->recordMeta([
            'password'      => 'hunter2',
            'api_key'       => 'SG.livekey',
            'client_secret' => 'sh-abcdef',
            'authorization' => 'Bearer abc.def.ghi',
        ]);

        foreach (['hunter2', 'SG.livekey', 'sh-abcdef', 'Bearer abc.def.ghi'] as $secret) {
            self::assertStringNotContainsString($secret, $line);
        }
        self::assertStringContainsString('[redacted]', $line);
    }

    public function test_matching_is_case_insensitive_and_substring(): void
    {
        $line = $this->recordMeta([
            'SMTP_Password'   => 'hunter2',
            'stripeApiKeyOld' => 'sk_live_x',
        ]);

        self::assertStringNotContainsString('hunter2', $line);
        self::assertStringNotContainsString('sk_live_x', $line);
    }

    public function test_nested_meta_is_redacted_too(): void
    {
        // meta routinely carries a request payload.
        $line = $this->recordMeta(['payload' => ['user' => 'alice', 'password' => 'hunter2']]);

        self::assertStringNotContainsString('hunter2', $line);
        self::assertStringContainsString('alice', $line, 'non-secret context must survive');
    }

    public function test_ordinary_context_is_preserved(): void
    {
        // A deny-list, not an allow-list: dropping unknown fields would teach
        // people to stop using meta at all.
        $line = $this->recordMeta(['invoice_id' => 'inv-99', 'amount' => 1250, 'currency' => 'EUR']);

        self::assertStringContainsString('inv-99', $line);
        self::assertStringContainsString('1250', $line);
        self::assertStringContainsString('EUR', $line);
    }

    public function test_the_key_itself_still_appears(): void
    {
        // Knowing a password was involved is useful; knowing its value is not.
        $line = $this->recordMeta(['password' => 'hunter2']);

        self::assertStringContainsString('password', $line);
    }
}
