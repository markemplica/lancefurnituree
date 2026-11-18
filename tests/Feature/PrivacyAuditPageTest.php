<?php

namespace Tests\Feature;

use App\Models\PrivacyAuditEvent;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrivacyAuditPageTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('activityMetadata')]
    public function test_activity_summary_handles_optional_metadata(?array $metadata, string $expected): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        PrivacyAuditEvent::create([
            'action' => 'room_photo_retention_expired',
            'subject_type' => 'room_image',
            'subject_id' => 12,
            'metadata' => $metadata,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.privacy-audit'))->assertOk();

        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $summaries = (new DOMXPath($document))->query('//p[@class="privacy-detail-summary"]');
        $this->assertSame(1, $summaries->length);
        $this->assertSame($expected, trim(preg_replace('/\s+/', ' ', $summaries->item(0)->textContent)));
        $response->assertDontSee('@elseif', false);
    }

    public static function activityMetadata(): array
    {
        return [
            'retention without consent version' => [['retention_days' => 90], 'Retention period: 90 days'],
            'consent without retention' => [['consent_version' => 'v1'], 'Consent version: v1'],
            'processing purpose only' => [['purpose' => 'room_analysis'], 'Analyze the room photo'],
            'purpose takes precedence' => [['purpose' => 'room_analysis', 'retention_days' => 90, 'consent_version' => 'v1'], 'Analyze the room photo'],
            'retention takes precedence over consent' => [['retention_days' => 30, 'consent_version' => 'v1'], 'Retention period: 30 days'],
            'no metadata' => [null, 'No additional details recorded.'],
            'empty metadata' => [[], 'No additional details recorded.'],
            'null optional values' => [['purpose' => null, 'retention_days' => null, 'consent_version' => null], 'No additional details recorded.'],
        ];
    }
}
