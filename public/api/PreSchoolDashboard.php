<?php

namespace App\Livewire\Admin;

use App\Models\School;
use App\Models\SchoolRequiredDoc;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentSchoolApplication;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Models\StudentStrength;

#[Layout('layouts.app')]
class PreSchoolDashboard extends Component
{
    public School $school;

    #[Url]
    public string $search = '';

    #[Url]
    public string $intake = 'all';

    #[Url(as: 'agent_id')]
    public string $agentId = 'all';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $nationality = 'all';

    #[Url]
    public string $schoolFilter = 'all';

    #[Url]
    public string $pipeline = 'all';

    public array $allowedStatuses = [
        'pending',
        'interview',
        'selected',
        'rejected',
        'coe-applied',
        'coe-granted',
        'coe-rejected',
        'visa-applied',
        'visa-granted',
        'visa-rejected',
        'withdrawal',
    ];

    public array $allowedPreSchoolStatuses = [
        'new',
        'incomplete',
        'incomplete_language',
        'ready',
    ];

    public ?int $assignApplicationId = null;
    public ?int $assignSchoolId = null;
    public string $assignStudentName = '';
    public array $assignAvailableSchools = [];

    public array $reviewInputs = [];
    public array $schoolStatusInputs = [];
    public array $strengthInputs = [];

    public function mount(School $school): void
    {
        abort_unless(Auth::check() && Auth::user()->role === 'admin', 403);

        $this->school = $school;
    }

    public function setPipeline(string $pipeline): void
    {
        $this->pipeline = $pipeline;
    }

    public function saveSchoolStatus(int $targetApplicationId): void
    {
        $status = $this->schoolStatusInputs[$targetApplicationId] ?? null;

        if (! $status || ! in_array($status, $this->allowedStatuses, true)) {
            session()->flash('error', 'Invalid status selected.');
            return;
        }

        $application = StudentSchoolApplication::findOrFail($targetApplicationId);

        $application->update([
            'status' => $status,
        ]);

        $this->schoolStatusInputs[$targetApplicationId] = $application->status;

        session()->flash('success', 'Application status updated successfully.');
    }

    public function saveReview(int $applicationId): void
    {
        $input = $this->reviewInputs[$applicationId] ?? null;

        if (! $input) {
            return;
        }

        $preSchoolStatus = $input['pre_school_status'] ?? 'new';
        $adminReviewNotes = $input['admin_review_notes'] ?? null;

        if (! in_array($preSchoolStatus, $this->allowedPreSchoolStatuses, true)) {
            return;
        }

        $application = StudentSchoolApplication::with('student')->findOrFail($applicationId);

        $application->student->update([
            'pre_school_status'  => $preSchoolStatus,
            'admin_review_notes' => filled($adminReviewNotes) ? $adminReviewNotes : null,
            'admin_reviewed_at'  => now(),
        ]);

        session()->flash('success', 'Pre-school review updated successfully.');
    }

    public function openAssignModal(int $applicationId): void
    {
        $application = StudentSchoolApplication::with('student.applications.school')->findOrFail($applicationId);
        $student = $application->student;

        $assignedSchoolIds = $student->applications
            ->pluck('school_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assignApplicationId = $application->id;
        $this->assignSchoolId = null;
        $this->assignStudentName = (string) ($student->student_name ?? 'Student');

        $this->assignAvailableSchools = School::query()
            ->whereNotIn('id', $assignedSchoolIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($school) => [
                'id' => $school->id,
                'name' => $school->name,
            ])
            ->values()
            ->toArray();

        $this->dispatch('open-assign-school-modal');
    }

    public function assignSchool(): void
    {
        $this->validate([
            'assignApplicationId' => ['required', 'integer', 'exists:student_school_applications,id'],
            'assignSchoolId' => ['required', 'integer', 'exists:schools,id'],
        ]);

        $application = StudentSchoolApplication::with('student')->findOrFail($this->assignApplicationId);

        if ((int) $application->school_id !== (int) $this->school->id) {
            abort(404);
        }

        $newApp = StudentSchoolApplication::firstOrCreate(
            [
                'student_id' => $application->student_id,
                'school_id'  => (int) $this->assignSchoolId,
            ],
            [
                'status'      => 'pending',
                'assigned_by' => auth()->id(),
                'applied_by'  => auth()->id(),
                'applied_at'  => now(),
            ]
        );

        if (! $newApp->wasRecentlyCreated) {
            session()->flash('error', 'This student is already assigned to that school.');
            return;
        }

        $this->dispatch('close-assign-school-modal');
        session()->flash('success', 'Student assigned to another school successfully.');
    }

    public function removeAssignedSchoolDirect(int $currentApplicationId, int $targetApplicationId): void
    {
        $application = StudentSchoolApplication::findOrFail($currentApplicationId);
        $targetApplication = StudentSchoolApplication::with('school')->findOrFail($targetApplicationId);

        if ((int) $application->school_id !== (int) $this->school->id) {
            abort(404);
        }

        if ((int) $application->student_id !== (int) $targetApplication->student_id) {
            session()->flash('error', 'Selected school assignment does not belong to this student.');
            return;
        }

        if ((int) $targetApplication->id === (int) $application->id) {
            session()->flash('error', 'You cannot remove the current school from this row.');
            return;
        }

        $targetSchoolName = $targetApplication->school?->name ?? 'selected school';

        $targetApplication->delete();

        unset($this->schoolStatusInputs[$targetApplicationId]);

        session()->flash('success', "Student removed from {$targetSchoolName} successfully.");
    }

    public function render()
    {
        $studentCount = StudentSchoolApplication::where('school_id', $this->school->id)->count();
        $user = User::where('school_id', $this->school->id)->first();

        $intakes = StudentSchoolApplication::query()
            ->join('students', 'students.id', '=', 'student_school_applications.student_id')
            ->where('student_school_applications.school_id', $this->school->id)
            ->whereNull('students.deleted_at')
            ->whereNotNull('students.intake')
            ->where('students.intake', '<>', '')
            ->distinct()
            ->orderByDesc('students.intake')
            ->pluck('students.intake');

        $nationalities = StudentSchoolApplication::query()
            ->join('students', 'students.id', '=', 'student_school_applications.student_id')
            ->where('student_school_applications.school_id', $this->school->id)
            ->whereNull('students.deleted_at')
            ->whereNotNull('students.nationality')
            ->where('students.nationality', '<>', '')
            ->distinct()
            ->orderBy('students.nationality')
            ->pluck('students.nationality');

        $agents = StudentSchoolApplication::query()
            ->join('students', 'students.id', '=', 'student_school_applications.student_id')
            ->join('users', 'users.id', '=', 'students.created_by')
            ->where('student_school_applications.school_id', $this->school->id)
            ->whereNull('students.deleted_at')
            ->select('users.id', 'users.name')
            ->distinct()
            ->orderBy('users.name')
            ->get();

        $allSchools = School::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $baseQuery = StudentSchoolApplication::query()
            ->with([
                'school',
                'student.creator',
                'student.applications.school',
                'student.strength',
            ])
            ->where('school_id', $this->school->id)
            ->whereHas('student', function ($q) {
                $q->whereNull('deleted_at');
            });

        if ($this->intake !== 'all' && $this->intake !== '') {
            $baseQuery->whereHas('student', function ($q) {
                $q->where('intake', $this->intake);
            });
        }

        if ($this->nationality !== 'all' && $this->nationality !== '') {
            $baseQuery->whereHas('student', function ($q) {
                $q->where('nationality', $this->nationality);
            });
        }

        if ($this->agentId !== 'all' && ctype_digit($this->agentId)) {
            $agentId = (int) $this->agentId;

            $baseQuery->whereHas('student', function ($q) use ($agentId) {
                $q->where('created_by', $agentId);
            });
        }

        if ($this->status !== 'all' && in_array($this->status, $this->allowedStatuses, true)) {
            $baseQuery->where('status', $this->status);
        }

        if ($this->schoolFilter !== 'all' && ctype_digit($this->schoolFilter)) {
            $schoolId = (int) $this->schoolFilter;

            $baseQuery->whereHas('student.applications', function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            });
        }

        if ($this->search !== '') {
            $search = $this->search;

            $baseQuery->whereHas('student', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('student_name', 'like', "%{$search}%")
                        ->orWhere('student_name_jp', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('passport_number', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('intake', 'like', "%{$search}%");
                });
            });
        }

        $applications = $baseQuery->latest()->get();

        $rows = $applications->map(function ($application) use ($allSchools) {
            $student = $application->student;
            $currentSchool = $application->school;

            if (! isset($this->strengthInputs[$student->id])) {
                $this->strengthInputs[$student->id] = [
                    'hiragana' => (int) ($student->strength->hiragana ?? 0),
                    'katagana' => (int) ($student->strength->katagana ?? 0),
                    'numbers' => (int) ($student->strength->numbers ?? 0),
                    'interview' => (int) ($student->strength->interview ?? 0),
                ];
            }

            $docOutput = collect();

            if ($currentSchool) {
                $requiredDocs = SchoolRequiredDoc::with('documentType')
                    ->where('school_id', $currentSchool->id)
                    ->get();

                foreach ($requiredDocs as $req) {
                    $dt = $req->documentType;

                    if (! $dt) {
                        continue;
                    }

                    $submitted = StudentDocument::query()
                        ->where('student_id', $student->id)
                        ->where('doc_type_id', $dt->id)
                        ->exists();

                    $docOutput->push([
                        'name' => $dt->doc_name,
                        'submitted' => $submitted,
                    ]);
                }
            }

            $assignedSchoolIds = $student->applications
                ->pluck('school_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $assignedSchools = $student->applications
                ->filter(fn ($app) => $app->school)
                ->map(function ($app) use ($application) {
                    return [
                        'application_id' => $app->id,
                        'school_id' => $app->school_id,
                        'school_name' => $app->school->name,
                        'status' => $app->status ?? 'pending',
                        'is_current' => (int) $app->id === (int) $application->id,
                    ];
                })
                ->values()
                ->toArray();

            foreach ($assignedSchools as $assignedSchool) {
                $assignedApplicationId = (int) $assignedSchool['application_id'];

                if (! isset($this->schoolStatusInputs[$assignedApplicationId])) {
                    $this->schoolStatusInputs[$assignedApplicationId] = $assignedSchool['status'] ?? 'pending';
                }
            }

            $availableSchools = $allSchools
                ->reject(fn ($s) => in_array((int) $s->id, $assignedSchoolIds, true))
                ->values();

            $photoUrl = null;

            if (! empty($student->photo)) {
                $photoUrl = asset('storage/' . ltrim($student->photo, '/'));
            } else {
                $photoDocument = StudentDocument::query()
                    ->where('student_id', $student->id)
                    ->whereHas('documentType', function ($q) {
                        $q->whereIn('file_type', ['jpg', 'jpeg']);
                    })
                    ->latest()
                    ->first();

                $photoUrl = $photoDocument ? Storage::url($photoDocument->file_path) : null;
            }

            $profileMeta = $this->buildProfileMeta($student);

            if (! isset($this->reviewInputs[$application->id])) {
                $this->reviewInputs[$application->id] = [
                    'pre_school_status' => $student->pre_school_status ?? 'new',
                    'admin_review_notes' => $student->admin_review_notes ?? '',
                ];
            }

            return [
                'application' => $application,
                'student' => $student,
                'school' => $currentSchool,
                'docs' => $docOutput,
                'photo_url' => $photoUrl,
                'available_schools' => $availableSchools,
                'assigned_schools' => $assignedSchools,
                'pipeline_stage' => $this->getPipelineStage($student),
                'profile_completion_percent' => $profileMeta['completion_percent'],
                'missing_profile_fields' => $profileMeta['missing_fields'],
                'assigned_real_school_count' => $student->applications->where('school_id', '!=', 1)->count(),
            ];
        })->values();

        $counts = [
            'all' => $rows->count(),
            'new' => $rows->where('pipeline_stage', 'new')->count(),
            'incomplete' => $rows->where('pipeline_stage', 'incomplete')->count(),
            'incomplete_language' => $rows->where('pipeline_stage', 'incomplete_language')->count(),
            'ready' => $rows->where('pipeline_stage', 'ready')->count(),
            'assigned' => $rows->where('pipeline_stage', 'assigned')->count(),
            'interview' => $rows->where('pipeline_stage', 'interview')->count(),
            'selected' => $rows->where('pipeline_stage', 'selected')->count(),
            'rejected_all' => $rows->where('pipeline_stage', 'rejected_all')->count(),
        ];

        if ($this->pipeline !== 'all') {
            $rows = $rows->filter(function ($row) {
                return $row['pipeline_stage'] === $this->pipeline;
            })->values();
        }

        return view('livewire.admin.pre-school-dashboard', [
            'school' => $this->school,
            'studentCount' => $studentCount,
            'user' => $user,
            'intakes' => $intakes,
            'nationalities' => $nationalities,
            'agents' => $agents,
            'allSchools' => $allSchools,
            'applications' => $applications,
            'rows' => $rows,
            'counts' => $counts,
        ]);
    }

    private function getPipelineStage(Student $student): string
    {
        $realApplications = $student->applications->where('school_id', '!=', 1);

        if ($realApplications->isEmpty()) {
            return $student->pre_school_status ?: 'new';
        }

        $statuses = $realApplications
            ->pluck('status')
            ->filter()
            ->map(fn ($status) => strtolower((string) $status))
            ->values();

        if ($statuses->contains(fn ($status) => in_array($status, ['selected', 'coe-applied', 'coe-granted', 'visa-applied', 'visa-granted'], true))) {
            return 'selected';
        }

        if ($statuses->contains('interview')) {
            return 'interview';
        }

        if (
            $realApplications->count() > 0 &&
            $statuses->count() === $realApplications->count() &&
            $statuses->every(fn ($status) => in_array($status, ['rejected', 'coe-rejected', 'visa-rejected', 'withdrawal'], true))
        ) {
            return 'rejected_all';
        }

        return 'assigned';
    }

    private function buildProfileMeta(Student $student): array
    {
        $fields = [
            'Student name' => !empty($student->student_name),
            'Student name JP' => !empty($student->student_name_jp),
            'DOB' => !empty($student->dob),
            'Gender' => !empty($student->gender),
            'Nationality' => !empty($student->nationality),
            'Phone' => !empty($student->phone),
            'Passport number' => !empty($student->passport_number),
            'Current address' => !empty($student->current_address),
            'Permanent address' => !empty($student->permanent_address),
            'Photo' => !empty($student->photo),
            'Father name' => !empty($student->father_name),
            'Father occupation' => !empty($student->father_occupation),
            'Mother name' => !empty($student->mother_name),
            'Mother occupation' => !empty($student->mother_occupation),
            'Marital status' => !empty($student->marital_status),
        ];

        $total = count($fields);
        $done = collect($fields)->filter()->count();

        return [
            'completion_percent' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
            'missing_fields' => collect($fields)
                ->filter(fn ($ok) => ! $ok)
                ->keys()
                ->values()
                ->toArray(),
        ];
    }

    public function saveStrength(int $studentId): void
    {
        $input = $this->strengthInputs[$studentId] ?? null;

        if (! $input) {
            session()->flash('error', 'Strength data not found.');
            return;
        }

        $data = [
            'overall' =>  (int) round(($input['hiragana'] + $input['katagana'] + $input['numbers'] + $input['interview']) / 4),
            'hiragana' => (int) ($input['hiragana'] ?? 0),
            'katagana' => (int) ($input['katagana'] ?? 0),
            'numbers' => (int) ($input['numbers'] ?? 0),
            'interview' => (int) ($input['interview'] ?? 0),
        ];

        foreach ($data as $key => $value) {
            if ($value < 0 || $value > 100) {
                session()->flash('error', ucfirst($key) . ' must be between 0 and 100.');
                return;
            }
        }

        StudentStrength::updateOrCreate(
            ['student_id' => $studentId],
            $data
        );

        session()->flash('success', 'Student language strength updated successfully.');
    }
}