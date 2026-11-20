<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Gitlab;

use ArrayObject;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\Service\Gitlab\ApprovalResolveService;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Gitlab\ApprovalResolveService
 *
 * What must hold:
 * - An owner's Approve, or thumbs-up on the merge request, replies on and resolves each open thread
 * - Resolved and non-resolvable threads are left alone
 * - A non-owner, an unset owner list, an unapproval or a taken-back thumbs-up touches nothing
 *
 * @coversDefaultClass \Kelpie\Service\Gitlab\ApprovalResolveService
 */
final class ApprovalResolveServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * @var ArrayObject<int, string>
     */
    private ArrayObject $requests;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ApprovalResolveService::class);
    }

    /**
     * The owner's Approve answers and resolves every open thread, and only those
     *
     * @return void
     */
    public function testOwnerApprovalResolvesOpenThreads(): void
    {
        putenv('KELPIE_APPROVAL_OWNERS=cwolsen, other');
        $outcome = $this->service()->handle('Merge Request Hook', $this->approved('cwolsen'));
        putenv('KELPIE_APPROVAL_OWNERS');

        $this->assertSame('resolved 2 of 2 open threads on approval by cwolsen', $outcome);
        $this->assertSame([
            'GET /api/v4/projects/7/merge_requests/3/discussions',
            'POST /api/v4/projects/7/merge_requests/3/discussions/open1/notes',
            'PUT /api/v4/projects/7/merge_requests/3/discussions/open1',
            'POST /api/v4/projects/7/merge_requests/3/discussions/open2/notes',
            'PUT /api/v4/projects/7/merge_requests/3/discussions/open2',
        ], $this->requests->getArrayCopy());
    }

    /**
     * A thumbs-up on the merge request counts like Approve; one taken back, or on a note, does not
     *
     * @return void
     */
    public function testThumbsUpOnTheMergeRequest(): void
    {
        putenv('KELPIE_APPROVAL_OWNERS=cwolsen');

        $this->assertStringStartsWith('resolved 2 of 2', $this->service()->handle('Emoji Hook', $this->emoji('MergeRequest', 'award')));
        $this->assertStringStartsWith('ignored', $this->service()->handle('Emoji Hook', $this->emoji('MergeRequest', 'revoke')));
        $this->assertStringStartsWith('ignored', $this->service()->handle('Emoji Hook', $this->emoji('Note', 'award')));
        putenv('KELPIE_APPROVAL_OWNERS');
    }

    /**
     * Anyone not listed, or no list at all, or an unapproval, changes nothing
     *
     * @return void
     */
    public function testNothingIsTouchedWithoutAnOwnerApproval(): void
    {
        putenv('KELPIE_APPROVAL_OWNERS=cwolsen');
        $this->assertSame('ignored: kelpie-bot is not an approval owner', $this->service()->handle('Merge Request Hook', $this->approved('kelpie-bot')));
        $this->assertSame([], $this->requests->getArrayCopy());
        $this->assertSame('ignored: not an approval', $this->service()->handle('Merge Request Hook', $this->approved('cwolsen', 'unapproved')));

        putenv('KELPIE_APPROVAL_OWNERS');
        $this->assertSame('ignored: KELPIE_APPROVAL_OWNERS is not set', $this->service()->handle('Merge Request Hook', $this->approved('cwolsen')));
        $this->assertSame([], $this->requests->getArrayCopy());
    }

    /**
     * @param string $user   The approver
     * @param string $action The merge request action
     *
     * @return array<string, mixed>
     */
    private function approved(string $user, string $action = 'approved'): array
    {
        return ['object_kind' => 'merge_request', 'user' => ['username' => $user], 'project' => ['id' => 7], 'object_attributes' => ['iid' => 3, 'action' => $action]];
    }

    /**
     * @param string $on   The awardable type
     * @param string $type Award or revoke
     *
     * @return array<string, mixed>
     */
    private function emoji(string $on, string $type): array
    {
        return ['object_kind' => 'emoji', 'event_type' => $type, 'user' => ['username' => 'cwolsen'], 'project' => ['id' => 7], 'merge_request' => ['iid' => 3], 'object_attributes' => ['name' => 'thumbsup', 'awardable_type' => $on]];
    }

    /**
     * A service over a fake GitLab with two open threads, one resolved and one that cannot be resolved
     *
     * @return ApprovalResolveService
     */
    private function service(): ApprovalResolveService
    {
        $logger         = $this->createStub(Logger::class);
        $json           = new JsonService($logger);
        $this->requests = new ArrayObject();
        $requests       = $this->requests;
        $note           = static function (int $id, bool $resolvable, bool $resolved): array {
            return ['id' => $id, 'body' => 'finding', 'author' => ['username' => 'reviewer'], 'created_at' => '2026-11-20T01:00:00.000Z', 'system' => false, 'resolvable' => $resolvable, 'resolved' => $resolved];
        };
        $discussions    = $json->encode([
            ['id' => 'open1', 'notes' => [$note(1, true, false)]],
            ['id' => 'done', 'notes' => [$note(2, true, true)]],
            ['id' => 'plain', 'notes' => [$note(3, false, false)]],
            ['id' => 'open2', 'notes' => [$note(4, true, false)]],
        ]);

        $http = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(static function (Request $request) use ($requests, $discussions): Psr7Response {
            $requests->append($request->getMethod() . ' ' . $request->getUri()->getPath());
            if ($request->getMethod() === 'GET') {
                return new Psr7Response(200, [], $discussions);
            }

            return new Psr7Response($request->getMethod() === 'POST' ? 201 : 200, [], '{"id": 99}');
        });

        $factory = new Psr17Factory();

        return new ApprovalResolveService($logger, new GitlabClientFactory($logger, $http, $factory, $factory, $json, new AutofixSettings('https://gitlab.test/api/v4', 'tok-bot')));
    }
}
