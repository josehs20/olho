@push('scripts')
    <script>
        (function () {
            var copyButton = document.getElementById('copy-pix');
            var payload = document.getElementById('pix-payload');

            if (copyButton && payload) {
                copyButton.addEventListener('click', function () {
                    var text = payload.textContent.trim();
                    var fallback = function () {
                        var range = document.createRange();
                        range.selectNodeContents(payload);
                        var selection = window.getSelection();
                        selection.removeAllRanges();
                        selection.addRange(range);
                    };

                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(text).then(function () {
                            copyButton.textContent = @json(__('Código PIX copiado'));
                        }).catch(fallback);
                    } else {
                        fallback();
                    }
                });
            }

            var statusUrl = @json($statusUrl);
            var state = {
                status: @json($progress['status']),
                completed: @json((int) $progress['completed_sources']),
                discoveries: @json((int) $progress['discoveries_total']),
                report: @json((bool) $progress['report_available']),
            };
            var attempts = 0;

            var timer = window.setInterval(function () {
                attempts++;

                fetch(statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function (response) { return response.ok ? response.json() : null; })
                    .then(function (data) {
                        if (! data || ! data.progress) { return; }

                        var progress = data.progress;
                        var paid = data.payment && data.payment.status === 'paid';
                        var progressed = progress.status !== state.status
                            || progress.completed_sources > state.completed
                            || progress.discoveries_total > state.discoveries
                            || (progress.report_available && ! state.report)
                            || (paid && state.status === 'draft');

                        if (progressed) {
                            window.clearInterval(timer);
                            window.location.reload();
                        }
                    })
                    .catch(function () {});

                if (attempts >= 240) {
                    window.clearInterval(timer);
                }
            }, 5000);
        })();
    </script>
@endpush
