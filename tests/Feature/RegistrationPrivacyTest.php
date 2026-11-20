<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function signup(array $extra = []): array
    {
        return array_merge([
            'first_name' => 'Privacy', 'last_name' => 'Customer',
            'email' => 'privacy-signup@example.test',
            'phone' => '09171234567',
            'password' => 'PrivacyTest12345', 'password_confirmation' => 'PrivacyTest12345',
        ], $extra);
    }

    public static function omittedDemographics(): array
    {
        return [
            'not sent' => [[]],
            'blank fields' => [['birthdate' => '', 'gender' => '']],
            'explicit nulls' => [['birthdate' => null, 'gender' => null]],
        ];
    }

    #[DataProvider('omittedDemographics')]
    public function test_signup_without_demographics_still_requires_email_verification(array $extra): void
    {
        Notification::fake();
        $existing = User::factory()->create(['birthdate' => '1990-05-12', 'gender' => 'female']);

        $this->post('/register', $this->signup($extra))->assertRedirect(route('verification.notice'));
        $user = User::where('email', 'privacy-signup@example.test')->firstOrFail();
        $this->assertNull($user->birthdate);
        $this->assertNull($user->gender);
        $this->assertSame('customer', $user->role);
        $this->assertFalse($user->is_active);
        $this->assertNull($user->email_verified_at);
        $this->assertGuest();
        Notification::assertSentTo($user, EmailVerificationCodeNotification::class);
        $this->assertSame('1990-05-12', $existing->fresh()->birthdate->format('Y-m-d'));
        $this->assertSame('female', $existing->fresh()->gender);
    }

    public function test_valid_optional_details_can_still_be_saved(): void
    {
        Notification::fake();
        $this->post('/register', $this->signup(['birthdate' => '2000-01-15', 'gender' => 'female']))
            ->assertRedirect(route('verification.notice'));
        $user = User::where('email', 'privacy-signup@example.test')->firstOrFail();
        $this->assertSame('2000-01-15', $user->birthdate->format('Y-m-d'));
        $this->assertSame('female', $user->gender);
    }

    public static function invalidDemographics(): array
    {
        return [
            'bad date' => [['birthdate' => 'not-a-date'], 'birthdate'],
            'future date' => [['birthdate' => '2999-01-01'], 'birthdate'],
            'unsupported gender choice' => [['gender' => 'non_binary'], 'gender'],
            'array gender' => [['gender' => ['female']], 'gender'],
        ];
    }

    #[DataProvider('invalidDemographics')]
    public function test_supplied_optional_details_are_still_validated(array $extra, string $field): void
    {
        Notification::fake();
        $this->postJson('/register', $this->signup($extra))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('users', 0);
        Notification::assertNothingSent();
    }

    public function test_signup_exposes_privacy_notice_before_submission_and_fields_are_optional(): void
    {
        $response = $this->get('/register')->assertOk()
            ->assertSee('Birthdate and gender are optional account details.')
            ->assertSee('We use your name and email to create and verify your account.');
        $html = $response->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $this->assertFalse($document->getElementById('birthdate')->hasAttribute('required'));
        $this->assertFalse($document->getElementById('gender')->hasAttribute('required'));
        $this->assertTrue($document->getElementById('phone')->hasAttribute('required'));
        $xpath = new \DOMXPath($document);
        $links = $xpath->query('//*[@id="registration-privacy-notice"]//a');
        $this->assertSame(route('privacy-policy'), $links->item(0)->getAttribute('href'));
        $this->assertLessThan(strpos($html, 'aria-describedby="registration-privacy-notice"'), strpos($html, 'id="registration-privacy-notice"'));
        $this->get(route('privacy-policy'))->assertOk()
            ->assertSee('not used for account verification or furniture ranking');
    }
}
