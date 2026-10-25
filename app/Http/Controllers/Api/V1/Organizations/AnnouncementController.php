<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Announcements\CancelAnnouncementRequest;
use App\Http\Requests\Api\V1\Announcements\StoreAnnouncementRequest;
use App\Http\Requests\Api\V1\Announcements\UpdateAnnouncementRequest;
use App\Http\Resources\V1\AnnouncementResource;
use App\Jobs\SendAnnouncement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AnnouncementController extends Controller
{
    /**
     * The gym's announcements, latest first by when they go, 25 per page.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Announcement::class);

        $page = Announcement::query()
            ->with(AnnouncementResource::RELATIONS)
            ->orderByDesc('send_at')
            ->orderByDesc('id')
            ->paginate(25);

        return AnnouncementResource::collection($page);
    }

    /**
     * Post an announcement, to go now or at send_at. The answer says how many
     * members it would reach now; who it reaches is worked out when it goes.
     */
    public function store(StoreAnnouncementRequest $request, CurrentStaff $currentStaff): JsonResponse
    {
        $announcement = Announcement::post(
            postedBy: $currentStaff->get(),
            title: $request->string('title')->toString(),
            body: $request->string('body')->toString(),
            branch: $request->chosenBranch(),
            includeRecentlyExpired: $request->boolean('include_recently_expired'),
            sendAt: $request->sendAt(),
        );
        $audienceCount = $announcement->audience()->count();

        if ($request->sendAt() === null) {
            SendAnnouncement::dispatch($announcement);
        }

        return (new AnnouncementResource($announcement->refresh()->load(AnnouncementResource::RELATIONS)))
            ->additional(['meta' => ['audience_count' => $audienceCount]])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Change a scheduled announcement: only the fields that are sent.
     */
    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        if (! $announcement->edit($request->changes())) {
            throw ValidationException::withMessages(['announcement' => 'Only an announcement that hasn\'t gone out can be changed.']);
        }

        return new AnnouncementResource($announcement->load(AnnouncementResource::RELATIONS));
    }

    /**
     * Cancel a scheduled announcement, so it never goes.
     */
    public function cancel(CancelAnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        if (! $announcement->cancel()) {
            throw ValidationException::withMessages(['announcement' => 'Only an announcement that hasn\'t gone out can be cancelled.']);
        }

        return new AnnouncementResource($announcement->load(AnnouncementResource::RELATIONS));
    }
}
