@push('scripts')
    {{-- Polling leve (~4s) enquanto a investigação está em processamento: o
         navegador se mantém sincronizado com o backend e recarrega a página só
         quando o estado observável muda. Sem framework novo — fetch + JSON,
         igual ao acompanhamento da avulsa. --}}
    <script>
        (function () {
            var statusUrl = @json($statusUrl);
            var state = {
                status: @json($progress['status']),
                run: @json($progress['run']['status'] ?? null),
                completed: @json((int) $progress['completed_sources']),
                discoveries: @json((int) $progress['discoveries_total']),
                report: @json((bool) $progress['report_available']),
            };

            // Só faz polling enquanto há processamento ativo; estado terminal não gera rajada.
            var terminal = ['completed', 'partial', 'failed', 'cancelled', 'expired'];
            if (terminal.indexOf(state.status) !== -1) {
                return;
            }

            var attempts = 0;
            var MAX_ATTEMPTS = 300; // ~20 minutos a 4s

            var timer = window.setInterval(function () {
                attempts++;

                fetch(statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function (response) { return response.ok ? response.json() : null; })
                    .then(function (data) {
                        if (! data || ! data.progress) { return; }

                        var progress = data.progress;
                        var progressed = progress.status !== state.status
                            || (progress.run && progress.run.status !== state.run)
                            || progress.completed_sources > state.completed
                            || progress.discoveries_total > state.discoveries
                            || (progress.report_available && ! state.report);

                        if (progressed) {
                            window.clearInterval(timer);
                            window.location.reload();
                        }
                    })
                    .catch(function () {});

                if (attempts >= MAX_ATTEMPTS) {
                    window.clearInterval(timer);
                }
            }, 4000);
        })();
    </script>
@endpush
