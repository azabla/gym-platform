<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Members\Exceptions\MemberPhotoRefused;
use App\Domains\Members\Models\Member;
use App\Domains\Members\Photos\MemberPhoto;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Take or replace a member's photo at the desk (ADR 0036). On a phone or a
 * tablet the browser offers the camera. The image editor crops it square; the
 * photo is kept as a small JPEG without what the camera wrote into it.
 */
class MemberPhotoAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'photo';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (Member $record): string => $record->photo_path === null ? __('Add photo') : __('Change photo'))
            ->icon(Heroicon::OutlinedCamera)
            ->color('gray')
            ->modalIcon(Heroicon::OutlinedCamera)
            ->modalWidth(Width::Medium)
            ->modalDescription(__('So the desk can see that the person at the door is the member. Take it with the camera or choose a picture; it is cropped square.'))
            ->visible(fn (Member $record): bool => Gate::allows('update', $record))
            ->schema([
                FileUpload::make('photo')
                    ->hiddenLabel()
                    ->image()
                    ->imageEditor()
                    ->imageEditorAspectRatioOptions(['1:1'])
                    ->automaticallyCropImagesToAspectRatio()
                    ->imageAspectRatio('1:1')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(5120)
                    ->storeFiles(false)
                    ->helperText(__('JPEG, PNG or WebP, up to 5 MB.'))
                    ->required(),
            ])
            ->action(function (array $data, Member $record, Action $action): void {
                $upload = $data['photo'];

                try {
                    app(MemberPhoto::class)->store($record, $upload instanceof TemporaryUploadedFile ? (string) $upload->get() : '');
                } catch (MemberPhotoRefused $refusal) {
                    Refusals::halt($action, $refusal);
                }

                Notification::make()->success()->title(__('Photo saved.'))->send();
            });
    }
}
