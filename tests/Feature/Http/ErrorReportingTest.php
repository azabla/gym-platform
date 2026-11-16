<?php

declare(strict_types=1);

use App\Domains\Memberships\Exceptions\MembershipRefused;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Validation\ValidationException;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->transport = new class implements TransportInterface
    {
        /** @var list<Event> */
        public array $sent = [];

        public function send(Event $event): Result
        {
            $this->sent[] = $event;

            return new Result(ResultStatus::success(), $event);
        }

        public function close(?int $timeout = null): Result
        {
            return new Result(ResultStatus::success());
        }
    };

    SentrySdk::getCurrentHub()->bindClient(
        ClientBuilder::create(['dsn' => 'https://public@sentry.example.com/1', 'transport' => $this->transport])->getClient(),
    );
});

it('sends an unhandled error to Sentry with only the user\'s id and the gym', function () {
    $gym = actingAsOrganization(Organization::factory()->create(['slug' => 'demo-gym']));
    $staff = actingAsStaff($gym, StaffRole::Receptionist);

    report(new RuntimeException('Something broke.'));

    $event = collect($this->transport->sent)->sole();
    expect($event->getExceptions()[0]->getValue())->toBe('Something broke.')
        ->and($event->getTags())->toMatchArray(['gym' => 'demo-gym'])
        ->and($event->getUser()?->getId())->toBe((string) $staff->user_id)
        ->and($event->getUser()?->getEmail())->toBeNull()
        ->and($event->getUser()?->getIpAddress())->toBeNull();
});

it('doesn\'t send the answers the platform expects: refusals, validation errors and 404s', function () {
    report(MembershipRefused::cancelled());
    report(ValidationException::withMessages(['phone' => 'The phone is taken.']));
    report(new NotFoundHttpException);

    expect($this->transport->sent)->toBe([]);
});
