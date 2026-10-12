{{--
    Badge de status do teste (prompt 47 §5): PENDING / RUNNING / SUCCESS /
    PARTIAL / FAILED / TIMEOUT / SKIPPED. Recebe `$status` (ModuleTestStatus).
--}}
@php
    /** @var \App\ModuleTests\ModuleTestStatus $status */
    $classes = match ($status) {
        \App\ModuleTests\ModuleTestStatus::Success => 'bg-emerald-500/15 text-emerald-300',
        \App\ModuleTests\ModuleTestStatus::Partial => 'bg-amber-500/15 text-amber-300',
        \App\ModuleTests\ModuleTestStatus::Failed => 'bg-rose-500/15 text-rose-300',
        \App\ModuleTests\ModuleTestStatus::Timeout => 'bg-orange-500/15 text-orange-300',
        \App\ModuleTests\ModuleTestStatus::Skipped => 'bg-ink-700/60 text-mist-400',
        \App\ModuleTests\ModuleTestStatus::Running => 'bg-signal-500/15 text-signal-300',
        \App\ModuleTests\ModuleTestStatus::Pending => 'bg-ink-700/60 text-mist-300',
    };
@endphp
<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $classes }}"
      data-test-status="{{ $status->value }}">{{ $status->label() }}</span>
