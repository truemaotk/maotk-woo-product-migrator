(function ($) {
    'use strict';

    function collectForm($form) {
        var data = {};
        $.each($form.serializeArray(), function (_, field) {
            data[field.name] = field.value;
        });
        return data;
    }

    function ajax(action, payload) {
        payload = payload || {};
        payload.action = action;
        payload.nonce = MaoTKWPM.nonce;
        return $.post(MaoTKWPM.ajaxUrl, payload);
    }

    function runnerText(text) {
        $('#maotk-wpm-runner .maotk-wpm-runner-text').text(text);
    }

    function updateProgress(response) {
        var data = response && response.data ? response.data : response;
        if (!data) {
            return;
        }

        var stats = data.stats || {};
        var total = parseInt(stats.total || 0, 10);
        var success = parseInt(stats.success || 0, 10);
        var failed = parseInt(stats.failed || 0, 10);
        var skipped = parseInt(stats.skipped || 0, 10);
        var done = success + failed + skipped;
        var progress = total ? Math.round((done / total) * 100) : 0;

        $('#maotk-wpm-runner .maotk-wpm-progress span').css('width', progress + '%');
        runnerText(
            '任务 #' + data.jobId +
            ' | 状态：' + (data.status || '-') +
            ' | 进度：' + done + ' / ' + total +
            ' | 成功：' + success +
            ' | 失败：' + failed +
            ' | 跳过：' + skipped +
            ' | ' + (data.message || '')
        );
    }

    function showError(xhr) {
        var message = '请求失败。';
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            message = xhr.responseJSON.data.message;
        }
        runnerText(message);
    }

    function processJob(jobId) {
        ajax('maotk_wpm_process_job', { job_id: jobId })
            .done(function (response) {
                updateProgress(response);
                if (response && response.success && response.data && !response.data.done) {
                    window.setTimeout(function () {
                        processJob(jobId);
                    }, 650);
                } else {
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 900);
                }
            })
            .fail(showError);
    }

    $(document).on('click', '.maotk-wpm-preset', function () {
        var $button = $(this);
        var target = $button.closest('.maotk-wpm-presets').data('target');
        $(target).val($button.data('value'));
        $button.addClass('is-selected').siblings().removeClass('is-selected');
    });

    $('#maotk-wpm-export-form').on('submit', function (event) {
        event.preventDefault();
        var data = collectForm($(this));
        runnerText('正在创建导出任务...');
        ajax('maotk_wpm_create_export_job', data)
            .done(function (response) {
                updateProgress(response);
                if (response.success && response.data && response.data.jobId) {
                    processJob(response.data.jobId);
                }
            })
            .fail(showError);
    });

    $('#maotk-wpm-import-path-form').on('submit', function (event) {
        event.preventDefault();
        var data = collectForm($(this));
        runnerText('正在创建导入任务...');
        ajax('maotk_wpm_create_import_job_path', data)
            .done(function (response) {
                updateProgress(response);
                if (response.success && response.data && response.data.jobId) {
                    processJob(response.data.jobId);
                }
            })
            .fail(showError);
    });

    $(document).on('click', '.maotk-wpm-run-job', function () {
        var jobId = $(this).data('job-id');
        runnerText('继续运行任务 #' + jobId + '...');
        processJob(jobId);
    });

    $(document).on('click', '.maotk-wpm-retry-job', function () {
        var jobId = $(this).data('job-id');
        runnerText('正在把失败项重新加入队列...');
        ajax('maotk_wpm_retry_failed', { job_id: jobId })
            .done(function (response) {
                updateProgress(response);
                processJob(jobId);
            })
            .fail(showError);
    });

    $(document).on('click', '.maotk-wpm-clean-job', function () {
        var jobId = $(this).data('job-id');
        if (!window.confirm('确认清理这个任务的临时文件？导出包如果在临时目录里也可能被删除。')) {
            return;
        }
        ajax('maotk_wpm_cleanup_job', { job_id: jobId })
            .done(function (response) {
                updateProgress(response);
                window.setTimeout(function () {
                    window.location.reload();
                }, 700);
            })
            .fail(showError);
    });
})(jQuery);
