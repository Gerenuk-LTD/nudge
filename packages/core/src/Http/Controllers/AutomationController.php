<?php

namespace Gerenuk\Nudge\Http\Controllers;

use Gerenuk\Nudge\Actions\Automations\ActivateAutomation;
use Gerenuk\Nudge\Automations\ActivationChecklist;
use Gerenuk\Nudge\Automations\AutomationApproval;
use Gerenuk\Nudge\Automations\AutomationDefinition;
use Gerenuk\Nudge\Automations\AutomationDefinitionValidator;
use Gerenuk\Nudge\Automations\StepType;
use Gerenuk\Nudge\Automations\SyncAutomationEmails;
use Gerenuk\Nudge\Contracts\NudgeUser;
use Gerenuk\Nudge\Enums\AutomationRunStatus;
use Gerenuk\Nudge\Enums\AutomationStatus;
use Gerenuk\Nudge\Enums\AutomationTrigger;
use Gerenuk\Nudge\Enums\Permission;
use Gerenuk\Nudge\Http\Requests\AutomationRequest;
use Gerenuk\Nudge\Models\Automation;
use Gerenuk\Nudge\Models\AutomationRun;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\ContactList;
use Gerenuk\Nudge\Models\CustomFieldDefinition;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Models\Segment;
use Gerenuk\Nudge\Models\Tag;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Segments\SegmentField;
use Gerenuk\Nudge\Segments\SegmentFieldCatalog;
use Gerenuk\Nudge\Support\MergeTagOptions;
use Gerenuk\Nudge\Templates\TemplateChoice;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function index(): Response
    {
        return Inertia::render('nudge/automations/index', [
            'automations' => Automation::query()
                ->withCount(['runs as active_count' => fn ($query) => $query->whereIn('status', [AutomationRunStatus::Active, AutomationRunStatus::Waiting])])
                ->orderBy('name')
                ->get()
                ->map(fn (Automation $automation) => [
                    'id' => $automation->id,
                    'name' => $automation->name,
                    'status' => $automation->status->value,
                    'trigger' => $automation->trigger_type->label(),
                    'entered_count' => $automation->entered_count,
                    'completed_count' => $automation->completed_count,
                    'active_count' => $automation->getAttribute('active_count'),
                    'outline' => array_column($automation->definition['steps'] ?? [], 'type'),
                ]),
            'triggers' => array_map(fn (AutomationTrigger $trigger) => ['value' => $trigger->value, 'label' => $trigger->label()], AutomationTrigger::cases()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'trigger_type' => ['required', 'string'],
        ]);

        $automation = Automation::create([
            'name' => $validated['name'],
            'trigger_type' => AutomationTrigger::tryFrom($validated['trigger_type']) ?? AutomationTrigger::ListJoined,
            'trigger_config' => [],
            'definition' => ['steps' => []],
        ]);

        return to_route('nudge.automations.edit', $automation);
    }

    public function edit(Automation $automation, ActivationChecklist $checklist, SegmentFieldCatalog $catalog, TemplateChoice $templateChoice): Response
    {
        $workspace = $this->currentWorkspace->getOrFail();

        return Inertia::render('nudge/automations/edit', [
            'automation' => [
                'id' => $automation->id,
                'name' => $automation->name,
                'status' => $automation->status->value,
                'trigger_type' => $automation->trigger_type->value,
                'trigger_config' => (object) ($automation->trigger_config ?? []),
                'allow_reentry' => $automation->allow_reentry,
                'definition' => $automation->definition,
                'entered_count' => $automation->entered_count,
                'completed_count' => $automation->completed_count,
                'approved_at' => $automation->approved_at?->toIso8601String(),
            ],
            'requiresApproval' => $workspace->requires_campaign_approval,
            'problems' => $checklist->problems($automation, $workspace),
            'stepStats' => $this->stepStats($automation),
            'triggers' => array_map(fn (AutomationTrigger $trigger) => ['value' => $trigger->value, 'label' => $trigger->label()], AutomationTrigger::cases()),
            'lists' => ContactList::query()->orderBy('name')->get(['id', 'name']),
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name']),
            'segments' => Segment::query()->orderBy('name')->get(['id', 'name']),
            'dateFields' => CustomFieldDefinition::query()->where('type', 'date')->orderBy('label')->get(['key', 'label']),
            'customFields' => CustomFieldDefinition::query()->orderBy('label')->get(['key', 'label', 'type']),
            'providers' => Provider::query()->orderBy('name')->get(['id', 'name', 'from_name', 'from_email', 'reply_to']),
            'segmentFields' => array_values(array_map(fn (SegmentField $field) => $field->toArray(), $catalog->all())),
            'mergeTags' => MergeTagOptions::all(),
            'templates' => Inertia::optional(fn () => $templateChoice->options()),
        ]);
    }

    public function update(AutomationRequest $request, Automation $automation, AutomationDefinitionValidator $validator, SyncAutomationEmails $emails): RedirectResponse
    {
        $validated = $request->validated();
        $validator->validate($validated['definition']);

        $workspace = $this->currentWorkspace->getOrFail();
        $user = $this->user($request);
        $this->ensureCanUseSteps($validated['definition'], $user, $workspace);
        $pausedForApproval = false;

        DB::transaction(function () use ($automation, $validated, $emails, $workspace, $user, &$pausedForApproval): void {
            $before = AutomationApproval::fingerprint($automation);

            $automation->fill([
                'name' => $validated['name'],
                'trigger_type' => $validated['trigger_type'],
                'trigger_config' => $validated['trigger_config'],
                'allow_reentry' => $validated['allow_reentry'],
                'definition' => ['steps' => $validated['definition']['steps'] ?? []],
            ]);

            $changed = AutomationApproval::fingerprint($automation) !== $before;
            $canApprove = $user->hasWorkspacePermission($workspace, Permission::CampaignsApprove);

            if ($changed && $canApprove && $automation->approved_at !== null) {
                // An approver's own edit stays approved, signed by them.
                $automation->fill(['approved_at' => now(), 'approved_by' => $user->getAuthIdentifier()]);
            } elseif ($changed && ! $canApprove && $automation->approved_at !== null) {
                $automation->fill(['approved_at' => null, 'approved_by' => null]);
            }

            if ($changed && ! $canApprove && $workspace->requires_campaign_approval && $automation->status === AutomationStatus::Active) {
                // Hold everyone where they are; the new emails are not published until an approver resumes it.
                $automation->status = AutomationStatus::Paused;
                $pausedForApproval = true;
            }

            $automation->save();
            $emails->handle($automation);
        });

        if ($pausedForApproval) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('Saved. The automation is paused until someone approves the changes.')]);
        }

        return back();
    }

    public function approve(Request $request, Automation $automation): RedirectResponse
    {
        $automation->update(['approved_at' => now(), 'approved_by' => $this->user($request)->getAuthIdentifier()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Automation approved.')]);

        return back();
    }

    public function activate(Request $request, Automation $automation, ActivateAutomation $activate): RedirectResponse
    {
        $this->ensureCanUseSteps($automation->definition, $this->user($request), $this->currentWorkspace->getOrFail());
        $activate->handle($automation, $this->currentWorkspace->getOrFail());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Automation is live.')]);

        return back();
    }

    /**
     * Pausing stops new contacts entering and holds everyone where they are until it is resumed.
     */
    public function pause(Automation $automation): RedirectResponse
    {
        abort_unless($automation->status === AutomationStatus::Active, 409);

        $automation->update(['status' => AutomationStatus::Paused]);

        return back();
    }

    public function destroy(Automation $automation): RedirectResponse
    {
        abort_if($automation->status === AutomationStatus::Active, 409, __('Pause the automation before deleting it.'));

        $automation->delete();

        return to_route('nudge.automations.index');
    }

    /**
     * Webhook steps send contact data out, and update-contact steps change contacts, so they need the matching
     * contact permission on top of managing automations.
     *
     * @param  array<string, mixed>  $definition
     */
    private function ensureCanUseSteps(array $definition, NudgeUser $user, Workspace $workspace): void
    {
        $steps = AutomationDefinition::from($definition);

        if ($steps->ofType(StepType::Webhook) !== [] && ! $user->hasWorkspacePermission($workspace, Permission::ContactsView)) {
            throw ValidationException::withMessages(['definition' => __('Webhook steps send contact details, so they need permission to view contacts.')]);
        }

        if ($steps->ofType(StepType::UpdateContact) !== [] && ! $user->hasWorkspacePermission($workspace, Permission::ContactsManage)) {
            throw ValidationException::withMessages(['definition' => __('Update contact steps need permission to edit contacts.')]);
        }
    }

    /**
     * Per step: how many contacts are there now, and how its email performed.
     *
     * @return array<string, array<string, int>>
     */
    private function stepStats(Automation $automation): array
    {
        $stats = [];

        $waiting = AutomationRun::query()
            ->where('automation_id', $automation->id)
            ->whereIn('status', [AutomationRunStatus::Active, AutomationRunStatus::Waiting])
            ->whereNotNull('current_step_id')
            ->selectRaw('current_step_id, count(*) as total')
            ->groupBy('current_step_id')
            ->pluck('total', 'current_step_id');

        foreach ($waiting as $stepId => $total) {
            $stats[(string) $stepId]['waiting'] = (int) $total;
        }

        foreach (Campaign::query()->where('automation_id', $automation->id)->get() as $campaign) {
            $stats[(string) $campaign->automation_step_id] = [
                ...($stats[(string) $campaign->automation_step_id] ?? []),
                'campaign_id' => $campaign->id,
                'sent' => $campaign->sent_count,
                'opened' => $campaign->opened_count,
                'clicked' => $campaign->clicked_count,
                'unsubscribed' => $campaign->unsubscribed_count,
                'bounced' => $campaign->bounced_count,
            ];
        }

        return $stats;
    }
}
