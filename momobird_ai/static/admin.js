/*
 * MomoBird AI admin page (Discuz! admincp). Sends the page's actions as POSTs with the
 * formhash and drives the full sync in batches of 20 from the browser:
 * sync_batch is called with the cursor the previous answer returned until it says done.
 * When another sync holds the lock the answer is busy with the same cursor (empty on a
 * first call); the page waits and sends that cursor again.
 */
(function () {
  'use strict';

  var root = document.getElementById('momobird-admin');
  if (!root) return;

  var url = root.getAttribute('data-url');
  var formhash = root.getAttribute('data-formhash');
  var message = document.getElementById('momobird-message');
  var statusBox = document.getElementById('momobird-status');
  var running = false;
  var BUSY_WAIT_MS = 3000;
  var BUSY_TRIES = 40;
  // Longer than any server run: 20 s engine budget + a 30 s request + slack.
  var REQUEST_TIMEOUT_MS = 120000;
  var LEAVE_TEXT = '全量同步还在进行，离开页面会中断（定时任务之后会接着补）。';

  function setMessage(text, kind) {
    message.style.display = text ? '' : 'none';
    message.className = 'marginbot' + (kind === 'error' ? ' error' : kind === 'success' ? ' diffcolor2' : '');
    message.textContent = text;
  }

  function setRunning(on) {
    running = on;
    var buttons = root.querySelectorAll('[data-mb-action]');
    for (var i = 0; i < buttons.length; i++) buttons[i].disabled = on;
    window.onbeforeunload = on ? function () { return LEAVE_TEXT; } : null;
  }

  function showStatus(payload) {
    if (payload && typeof payload.status_html === 'string') statusBox.innerHTML = payload.status_html;
  }

  function post(action, params, done) {
    var body = 'mb_action=' + encodeURIComponent(action) + '&formhash=' + encodeURIComponent(formhash) + '&inajax=1';
    for (var name in params) {
      if (Object.prototype.hasOwnProperty.call(params, name)) {
        body += '&' + encodeURIComponent(name) + '=' + encodeURIComponent(params[name]);
      }
    }
    var xhr = new XMLHttpRequest();
    var answered = false;
    function answer(payload) {
      if (answered) return;
      answered = true;
      done(payload);
    }
    xhr.open('POST', url, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.timeout = REQUEST_TIMEOUT_MS;
    xhr.onload = function () {
      var payload = null;
      try {
        payload = JSON.parse(xhr.responseText);
      } catch (e) {
        payload = null;
      }
      if (!payload || typeof payload !== 'object') {
        answer({ok: false, message: '请求失败（HTTP ' + xhr.status + '），请刷新页面后重试。'});
        return;
      }
      answer(payload);
    };
    xhr.onerror = function () {
      answer({ok: false, message: '连不上论坛服务器，请检查网络后刷新页面。'});
    };
    xhr.ontimeout = function () {
      answer({ok: false, message: '请求超时，请刷新页面看看同步状态。'});
    };
    xhr.send(body);
  }

  function summary(totals) {
    var parts = ['推送 ' + totals.pushed + ' 篇', '未变 ' + totals.unchanged + ' 篇', '撤下 ' + totals.removed + ' 篇'];
    if (totals.failed > 0) parts.push('失败 ' + totals.failed + ' 篇');
    if (totals.rejected > 0) parts.push('被拒绝 ' + totals.rejected + ' 篇');
    if (totals.held > 0) parts.push('暂缓 ' + totals.held + ' 篇');
    return parts.join('，');
  }

  function runFullSync() {
    var cursor = null;
    var busyTries = 0;
    var totals = {pushed: 0, unchanged: 0, removed: 0, failed: 0, rejected: 0, held: 0};
    setRunning(true);
    setMessage('正在准备全量同步…');

    function finish(text, kind, payload) {
      setRunning(false);
      showStatus(payload);
      setMessage(text, kind);
    }

    function step() {
      // null (start) goes out as an empty cursor; the server starts the sync for it.
      post('sync_batch', {cursor: cursor === null ? '' : cursor}, function (payload) {
        if (payload.busy) {
          busyTries++;
          if (busyTries > BUSY_TRIES) {
            finish(payload.message || '正在同步，请稍后再试', 'error', payload);
            return;
          }
          // Keep the same cursor: a busy answer did not move it.
          setMessage('另一个同步正在进行，稍后自动继续…（' + summary(totals) + '）');
          window.setTimeout(step, BUSY_WAIT_MS);
          return;
        }
        busyTries = 0;
        var result = payload.result || {};
        for (var key in totals) {
          if (Object.prototype.hasOwnProperty.call(totals, key)) totals[key] += result[key] || 0;
        }
        if (!payload.ok) {
          finish(summary(totals) + '。' + (payload.message || '同步没有完成。'), 'error', payload);
          return;
        }
        if (payload.done) {
          var problems = totals.failed + totals.rejected + totals.held > 0;
          finish((payload.message || '全量同步完成。') + summary(totals) + '。', problems ? 'error' : 'success', payload);
          return;
        }
        if (typeof payload.cursor !== 'string' || payload.cursor === '') {
          // Only a busy answer may come back without a cursor; anything else would restart the sync.
          finish(summary(totals) + '。同步进度丢失，请重新点「立即全量同步」。', 'error', payload);
          return;
        }
        cursor = payload.cursor;
        setMessage('正在同步，请保持页面打开…（' + summary(totals) + '）');
        step();
      });
    }

    step();
  }

  function runSimple(action, pending) {
    setRunning(true);
    setMessage(pending);
    post(action, {}, function (payload) {
      setRunning(false);
      showStatus(payload);
      setMessage(payload.message || (payload.ok ? '完成。' : '操作没有成功。'), payload.ok ? 'success' : 'error');
    });
  }

  root.addEventListener('click', function (event) {
    var target = event.target;
    while (target && target !== root && !(target.getAttribute && target.getAttribute('data-mb-action'))) {
      target = target.parentNode;
    }
    if (!target || target === root || running) return;
    var action = target.getAttribute('data-mb-action');
    if (action === 'sync') {
      runFullSync();
    } else if (action === 'test') {
      runSimple('test', '正在检查连接…');
    } else if (action === 'retry') {
      runSimple('retry', '正在重试失败项…');
    } else if (action === 'confirm_withdraw') {
      if (window.confirm('确认从 MomoBird 撤下这些在论坛里已经不该同步的主题？撤下后访客的问答里不会再引用它们。')) {
        runSimple('confirm_withdraw', '正在撤下…');
      }
    }
  });
}());
