<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">{{ __('Auditoria') }}</h2>
 </x-slot>

 <div class="py-12">
  <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
  @include('admin.partials.nav')

  <div class="overflow-hidden surface">
   <table class="min-w-full divide-y divide-gray-200 text-sm">
    <thead class="bg-ink-850/60">
    <tr>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Ação') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Ator') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Data') }}</th>
    </tr>
    </thead>
    <tbody class="divide-y divide-gray-200">
    @foreach ($audits as $audit)
     <tr>
      <td class="px-4 py-3">{{ $audit->action }}</td>
      <td class="px-4 py-3">{{ $audit->user?->email ?? '—' }}</td>
      <td class="px-4 py-3">{{ $audit->created_at->format('d/m/Y H:i:s') }}</td>
     </tr>
    @endforeach
    </tbody>
   </table>
  </div>

  <div class="mt-4">{{ $audits->links() }}</div>
  </div>
 </div>
</x-app-layout>
