<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$uid     = current_user()['id'];
$u       = current_user();
$conv_id = (int)($_GET['conv_id'] ?? 0);

$conversations = DB::fetchAll("
    SELECT c.*,
           a.title AS ad_title, a.slug AS ad_slug,
           (SELECT path FROM ad_photos WHERE ad_id=a.id AND is_primary=1 LIMIT 1) AS ad_photo,
           IF(c.buyer_id=?, u2.name,  u3.name)   AS other_name,
           IF(c.buyer_id=?, u2.avatar,u3.avatar)  AS other_avatar,
           IF(c.buyer_id=?, u2.id,    u3.id)      AS other_id,
           IF(c.buyer_id=?, c.buyer_unread, c.seller_unread) AS my_unread
    FROM conversations c
    LEFT JOIN ads a ON a.id=c.ad_id
    JOIN users u2 ON u2.id=c.buyer_id
    JOIN users u3 ON u3.id=c.seller_id
    WHERE (c.buyer_id=? OR c.seller_id=?)
    ORDER BY c.last_message_at DESC
", [$uid,$uid,$uid,$uid,$uid,$uid]);

$active_conv = null;
$messages    = [];
if ($conv_id) {
    $active_conv = DB::fetch("
        SELECT c.*, a.title AS ad_title, a.slug AS ad_slug, a.id AS ad_id,
               a.price AS ad_price, a.currency AS ad_currency,
               (SELECT path FROM ad_photos WHERE ad_id=a.id AND is_primary=1 LIMIT 1) AS ad_photo,
               IF(c.buyer_id=?,u2.name, u3.name)   AS other_name,
               IF(c.buyer_id=?,u2.avatar,u3.avatar) AS other_avatar,
               IF(c.buyer_id=?,u2.id,   u3.id)     AS other_id
        FROM conversations c
        LEFT JOIN ads a ON a.id=c.ad_id
        JOIN users u2 ON u2.id=c.buyer_id
        JOIN users u3 ON u3.id=c.seller_id
        WHERE c.id=? AND (c.buyer_id=? OR c.seller_id=?)
    ", [$uid,$uid,$uid,$conv_id,$uid,$uid]);

    if ($active_conv) {
        // Ultimele 200 de mesaje (nu primele 200), afișate cronologic
        $messages = array_reverse(DB::fetchAll("
            SELECT m.*, u.name AS sender_name, u.avatar AS sender_avatar
            FROM messages m JOIN users u ON u.id=m.sender_id
            WHERE m.conversation_id=? ORDER BY m.id DESC LIMIT 200
        ", [$conv_id]));
        DB::query("UPDATE messages SET is_read=1 WHERE conversation_id=? AND sender_id!=?", [$conv_id,$uid]);
        $col = $uid==$active_conv['buyer_id'] ? 'buyer_unread' : 'seller_unread';
        DB::query("UPDATE conversations SET $col=0 WHERE id=?", [$conv_id]);
        invalidate_unread_cache($uid);
    }
}

$total_unread = array_sum(array_column($conversations, 'my_unread'));
$seo_title = 'Mesaje' . ($total_unread > 0 ? " ($total_unread)" : '');

function vmsg_check($read) {
    if ($read) {
        return '<svg viewBox="0 0 24 16" class="ck ck-read" width="16" height="11"><path d="M1 8l4 4 9-10" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 12l1.6 1.6L21 2" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
    return '<svg viewBox="0 0 16 16" class="ck" width="13" height="11"><path d="M2 8l4 4 8-9" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}
function vchat_img($p) { return $p ? rtrim(BASE_URL,'/') . '/uploads/chat/' . $p : ''; }

include __DIR__ . '/includes/header.php';
?>
<?php if ($conv_id && $active_conv): ?>
<style>@media(max-width:768px){.mobile-bottom-nav{display:none!important}body{padding-bottom:0!important}}</style>
<?php endif; ?>

<style>
/* ════ CHAT — Veedo ════ */
.chat-wrap{display:grid;grid-template-columns:332px 1fr;
  height:calc(100dvh - 66px);overflow:hidden;background:var(--bg)}
.conv-list{display:flex;flex-direction:column;overflow:hidden;
  background:var(--surface);border-right:1px solid var(--border)}
.conv-head{padding:18px 18px 14px;flex-shrink:0;border-bottom:1px solid var(--border)}
.conv-head h1{font-family:var(--font-display);font-size:21px;font-weight:800;
  display:flex;align-items:center;gap:9px}
.conv-head .cnt{background:var(--grad-ember);color:#fff;font-size:12px;
  font-weight:700;padding:2px 9px;border-radius:100px}
.conv-scroll{overflow-y:auto;flex:1;scrollbar-width:thin}
.conv-item{display:flex;gap:12px;padding:13px 16px;cursor:pointer;
  text-decoration:none;color:inherit;position:relative;
  border-bottom:1px solid var(--border-2);transition:background .15s}
.conv-item:hover{background:var(--bg-2)}
.conv-item.active{background:var(--brand-light)}
.conv-item.active::before{content:"";position:absolute;left:0;top:0;bottom:0;
  width:3px;background:var(--grad-ember)}
.conv-av-wrap{position:relative;flex-shrink:0}
.conv-av{width:50px;height:50px;border-radius:50%;object-fit:cover;
  border:1.5px solid var(--border)}
.conv-meta{flex:1;min-width:0}
.conv-top{display:flex;align-items:center;gap:8px;margin-bottom:2px}
.conv-name{font-size:14px;font-weight:600;color:var(--text);flex:1;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.conv-time{font-size:11px;color:var(--text-3);flex-shrink:0}
.conv-ad{font-size:11px;color:var(--text-3);white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:4px}
.conv-ad::before{content:"";width:5px;height:5px;border-radius:1px;
  background:var(--brand);flex-shrink:0}
.conv-prev{font-size:12.5px;color:var(--text-3);margin-top:3px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.conv-item.unread .conv-name{font-weight:800}
.conv-item.unread .conv-prev{color:var(--text);font-weight:600}
.conv-badge{background:var(--grad-ember);color:#fff;font-size:11px;
  font-weight:700;min-width:20px;height:20px;border-radius:10px;
  display:grid;place-items:center;padding:0 6px;flex-shrink:0;align-self:center}

.chat-main{display:flex;flex-direction:column;overflow:hidden;
  background:var(--bg);position:relative}
.chat-head{display:flex;align-items:center;gap:12px;padding:11px 16px;
  background:var(--surface);border-bottom:1px solid var(--border);flex-shrink:0}
.chat-back{display:none;width:36px;height:36px;border-radius:50%;
  align-items:center;justify-content:center;color:var(--text-2);
  text-decoration:none;font-size:20px;flex-shrink:0}
.chat-head-av{width:42px;height:42px;border-radius:50%;object-fit:cover;
  border:1.5px solid var(--border)}
.chat-head-name{font-weight:700;font-size:15px;color:var(--text)}
.chat-head-sub{font-size:12px;color:var(--text-3)}
.chat-ad-card{display:flex;align-items:center;gap:9px;margin-left:auto;
  background:var(--bg-2);border:1px solid var(--border);border-radius:12px;
  padding:6px 11px 6px 6px;text-decoration:none;color:var(--text);
  flex-shrink:0;max-width:215px;transition:border-color .15s}
.chat-ad-card:hover{border-color:var(--border-brand)}
.chat-ad-thumb{width:38px;height:38px;border-radius:8px;object-fit:cover;
  background:var(--bg-3);flex-shrink:0}
.chat-ad-info{min-width:0}
.chat-ad-title{font-size:12px;font-weight:600;white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis}
.chat-ad-price{font-size:12px;font-weight:800;color:var(--brand);
  font-family:var(--font-display)}

.chat-safety{display:flex;align-items:flex-start;gap:9px;padding:9px 14px;
  background:var(--surface-2);border-bottom:1px solid var(--border);
  font-size:11.5px;line-height:1.45;color:var(--text-2);flex-shrink:0}
.chat-safety svg{flex-shrink:0;margin-top:1px}
.chat-safety b{color:var(--text);font-weight:700}
.chat-safety .cs-x{margin-left:auto;flex-shrink:0;background:none;border:none;
  color:var(--text-3);cursor:pointer;font-size:15px;padding:0 2px;line-height:1}
.chat-safety .cs-x:hover{color:var(--text)}

.chat-msgs{flex:1;overflow-y:auto;padding:18px 16px 8px;
  display:flex;flex-direction:column;gap:2px;scrollbar-width:thin;background:var(--bg)}
.date-sep{align-self:center;font-size:11px;font-weight:600;color:var(--text-3);
  background:var(--surface-2);border:1px solid var(--border-2);
  padding:4px 12px;border-radius:100px;margin:14px 0 8px}
.msg-row{display:flex;align-items:flex-end;gap:7px;max-width:78%}
.msg-row.mine{align-self:flex-end;flex-direction:row-reverse}
.msg-row.theirs{align-self:flex-start}
.msg-row.grp{margin-top:-1px}
.msg-av{width:30px;height:30px;border-radius:50%;object-fit:cover;
  flex-shrink:0;border:1px solid var(--border)}
.msg-av.hide{visibility:hidden}
.msg-stack{display:flex;flex-direction:column;min-width:0}
.msg-row.mine .msg-stack{align-items:flex-end}
.msg-bubble{padding:9px 13px;font-size:14px;line-height:1.45;
  word-break:break-word;border-radius:18px;max-width:100%}
.msg-row.mine .msg-bubble{background:var(--grad-ember);color:#fff;
  border-bottom-right-radius:5px}
.msg-row.theirs .msg-bubble{background:var(--surface);color:var(--text);
  border:1px solid var(--border);border-bottom-left-radius:5px}
.msg-row.grp.mine .msg-bubble{border-bottom-right-radius:18px;border-top-right-radius:5px}
.msg-row.grp.theirs .msg-bubble{border-bottom-left-radius:18px;border-top-left-radius:5px}
.msg-bubble.has-img{padding:4px;overflow:hidden}
.msg-img{display:block;max-width:248px;max-height:300px;width:100%;
  border-radius:14px;cursor:zoom-in;object-fit:cover;background:var(--bg-3)}
.msg-cap{padding:6px 9px 3px;font-size:14px;line-height:1.42}
.msg-meta{display:flex;align-items:center;gap:4px;margin-top:3px;padding:0 5px;
  font-size:10.5px;color:var(--text-3)}
.msg-row.mine .msg-meta{flex-direction:row-reverse}
.msg-status{display:flex;align-items:center;color:var(--text-3)}
.msg-status .ck-read{color:var(--brand)}
.msg-status.sending{opacity:.7}
.msg-status.error{color:var(--danger)}

.quick-replies{display:flex;gap:8px;padding:10px 14px 0;overflow-x:auto;
  scrollbar-width:none;flex-shrink:0;background:var(--surface)}
.quick-replies::-webkit-scrollbar{display:none}
.qr-chip{flex:0 0 auto;padding:7px 13px;border-radius:100px;
  background:var(--bg-2);border:1px solid var(--border);
  font-size:12.5px;font-weight:600;color:var(--text-2);cursor:pointer;
  white-space:nowrap;transition:transform .2s var(--spring),border-color .15s,color .15s}
.qr-chip:hover{color:var(--text);border-color:var(--border-brand)}
.qr-chip:active{transform:scale(.94)}

/* preview imagine atasata */
.img-preview-bar{display:none;align-items:center;gap:11px;padding:10px 14px;
  background:var(--surface);border-top:1px solid var(--border)}
.img-preview-bar.show{display:flex}
.img-preview-bar img{width:52px;height:52px;border-radius:10px;object-fit:cover;
  border:1px solid var(--border);flex-shrink:0}
.ipb-txt{flex:1;font-size:12.5px;color:var(--text-2);font-weight:600}
.img-preview-x{width:30px;height:30px;border-radius:50%;cursor:pointer;
  background:var(--bg-2);border:1px solid var(--border);color:var(--text-2);
  display:grid;place-items:center;flex-shrink:0}

.chat-input-wrap{display:flex;gap:9px;align-items:flex-end;padding:11px 14px;
  background:var(--surface);border-top:1px solid var(--border);flex-shrink:0}
@media(max-width:768px){.chat-input-wrap{
  padding-bottom:calc(11px + env(safe-area-inset-bottom,0px))}}
.attach-btn{width:44px;height:44px;border-radius:50%;flex-shrink:0;
  background:var(--bg-2);border:1.5px solid var(--border);color:var(--text-2);
  cursor:pointer;display:grid;place-items:center;
  transition:transform .25s var(--spring),border-color .15s,color .15s}
.attach-btn:hover{color:var(--brand);border-color:var(--border-brand)}
.attach-btn:active{transform:scale(.88)}
.chat-input{flex:1;padding:11px 15px;border:1.5px solid var(--border);
  border-radius:22px;font-size:14px;background:var(--bg-2);color:var(--text);
  outline:none;resize:none;max-height:120px;min-height:44px;line-height:1.4;
  font-family:var(--font-main);transition:border-color .15s}
.chat-input:focus{border-color:var(--brand)}
.chat-send{width:44px;height:44px;border-radius:50%;flex-shrink:0;
  background:var(--grad-ember);border:none;color:#fff;cursor:pointer;
  display:grid;place-items:center;transition:transform .25s var(--spring),opacity .15s}
.chat-send:active{transform:scale(.88)}
.chat-send:disabled{opacity:.45;cursor:default}

.scroll-fab{position:absolute;right:18px;bottom:150px;width:42px;height:42px;
  border-radius:50%;background:var(--surface);border:1px solid var(--border);
  box-shadow:var(--shadow-md);color:var(--text-2);cursor:pointer;
  display:grid;place-items:center;opacity:0;pointer-events:none;
  transform:translateY(8px);transition:opacity .2s,transform .25s var(--spring);z-index:5}
.scroll-fab.show{opacity:1;pointer-events:auto;transform:translateY(0)}
.scroll-fab .fab-dot{position:absolute;top:-2px;right:-2px;width:13px;height:13px;
  background:var(--brand);border-radius:50%;border:2px solid var(--surface);display:none}
.scroll-fab.has-new .fab-dot{display:block}

.chat-lightbox{position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.93);
  display:none;align-items:center;justify-content:center;padding:32px;cursor:zoom-out}
.chat-lightbox.show{display:flex}
.chat-lightbox img{max-width:100%;max-height:100%;border-radius:10px}
.chat-lightbox .lb-x{position:absolute;top:16px;right:22px;color:#fff;
  font-size:34px;cursor:pointer;line-height:1;opacity:.85}

.chat-empty{display:flex;flex-direction:column;align-items:center;
  justify-content:center;flex:1;color:var(--text-3);text-align:center;padding:40px}
.chat-empty .em-ic{font-size:3rem;margin-bottom:14px;opacity:.7}

@media(max-width:768px){
  .chat-wrap{grid-template-columns:1fr;height:calc(100dvh - 56px)}
  .conv-list{display:<?= $conv_id ? 'none' : 'flex' ?>}
  .chat-main{display:<?= $conv_id ? 'flex' : 'none' ?>}
  .chat-back{display:flex}
  .chat-ad-card{max-width:130px}
  .chat-ad-info{display:none}
  .msg-row{max-width:86%}
  .scroll-fab{bottom:160px}
}
</style>

<?php if (empty($conversations) && !$conv_id): ?>
<div style="text-align:center;padding:90px 20px;color:var(--text-3);">
  <div style="font-size:3.6rem;margin-bottom:16px;">💬</div>
  <h2 style="font-family:var(--font-display);font-size:1.3rem;margin-bottom:8px;color:var(--text);">Nicio conversație încă</h2>
  <p style="font-size:14px;margin-bottom:22px;">Contactează un vânzător dintr-un anunț ca să începi.</p>
  <a href="/" class="btn btn-primary">Explorează anunțuri →</a>
</div>

<?php else: ?>
<div class="chat-wrap">

  <aside class="conv-list">
    <div class="conv-head">
      <h1>Mesaje <?php if($total_unread>0): ?><span class="cnt"><?= $total_unread ?></span><?php endif; ?></h1>
    </div>
    <div class="conv-scroll">
      <?php foreach ($conversations as $conv): ?>
      <a href="/mesaje/<?= (int)$conv['id'] ?>"
         class="conv-item <?= $conv_id==$conv['id']?'active':'' ?> <?= $conv['my_unread']>0?'unread':'' ?>">
        <div class="conv-av-wrap">
          <img class="conv-av" src="<?= h(avatar_url($conv['other_avatar'], $conv['other_name'])) ?>" alt="">
        </div>
        <div class="conv-meta">
          <div class="conv-top">
            <span class="conv-name"><?= h($conv['other_name']) ?></span>
            <span class="conv-time"><?= $conv['last_message_at'] ? time_ago($conv['last_message_at']) : '' ?></span>
          </div>
          <div class="conv-ad"><?= h(mb_substr($conv['ad_title'] ?? 'Mesaj direct',0,38)) ?></div>
          <div class="conv-prev"><?= h(mb_substr($conv['last_message']??'Începe conversația…',0,46)) ?></div>
        </div>
        <?php if($conv['my_unread']>0): ?><span class="conv-badge"><?= (int)$conv['my_unread'] ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </aside>

  <main class="chat-main">
    <?php if ($active_conv): ?>

    <div class="chat-head">
      <a href="/mesaje" class="chat-back">←</a>
      <a href="/utilizator/<?= (int)$active_conv['other_id'] ?>" style="text-decoration:none;flex-shrink:0;">
        <img class="chat-head-av" src="<?= h(avatar_url($active_conv['other_avatar'], $active_conv['other_name'])) ?>" alt="">
      </a>
      <div style="flex:1;min-width:0;">
        <div class="chat-head-name"><?= h($active_conv['other_name']) ?></div>
        <div class="chat-head-sub"><?= $active_conv['ad_slug'] ? 'Conversație despre un anunț' : 'Mesaj direct' ?></div>
      </div>
      <?php if ($active_conv['ad_slug']): ?>
      <a href="/anunt/<?= h($active_conv['ad_slug']) ?>" class="chat-ad-card">
        <?php if ($active_conv['ad_photo']): ?>
          <img class="chat-ad-thumb" src="<?= h(photo_url($active_conv['ad_photo'])) ?>" alt="">
        <?php else: ?>
          <div class="chat-ad-thumb" style="display:grid;place-items:center;font-size:18px;">📦</div>
        <?php endif; ?>
        <div class="chat-ad-info">
          <div class="chat-ad-title"><?= h(mb_substr($active_conv['ad_title'],0,26)) ?></div>
          <div class="chat-ad-price"><?= $active_conv['ad_price'] ? h(format_price((float)$active_conv['ad_price'],$active_conv['ad_currency'])) : 'Preț la cerere' ?></div>
        </div>
      </a>
      <?php endif; ?>
    </div>

    <div class="chat-safety" id="chat-safety">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#E8920D" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 3v6c0 5-3.4 9.4-8 11-4.6-1.6-8-6-8-11V5l8-3z"/><path d="M9 11.5l2 2 4-4"/></svg>
      <span><b>Tranzacționează în siguranță:</b> vezi produsul în persoană, într-un loc public, și nu trimite bani în avans. Veedo nu intermediază plăți între utilizatori.</span>
      <button class="cs-x" id="cs-dismiss" aria-label="Închide" type="button">✕</button>
    </div>
    <script>
    (function(){
      var b=document.getElementById('chat-safety'); if(!b) return;
      try{ if(localStorage.getItem('veedo_chat_safety_seen')) b.style.display='none'; }catch(e){}
      var x=document.getElementById('cs-dismiss');
      if(x) x.addEventListener('click',function(){
        b.style.display='none';
        try{ localStorage.setItem('veedo_chat_safety_seen','1'); }catch(e){}
      });
    })();
    </script>

    <div class="chat-msgs" id="chat-msgs">
      <?php
      $prev_date = null;
      foreach ($messages as $i => $m):
          $is_mine   = $m['sender_id'] == $uid;
          $msg_date  = date('Y-m-d', strtotime($m['created_at']));
          $next      = $messages[$i+1] ?? null;
          $prev      = $messages[$i-1] ?? null;
          $is_grp = $prev && $prev['sender_id']==$m['sender_id']
                    && date('Y-m-d',strtotime($prev['created_at']))==$msg_date
                    && (strtotime($m['created_at'])-strtotime($prev['created_at']))<300;
          $last_of_grp = !$next || $next['sender_id']!=$m['sender_id']
                    || date('Y-m-d',strtotime($next['created_at']))!=$msg_date
                    || (strtotime($next['created_at'])-strtotime($m['created_at']))>=300;
          if ($msg_date !== $prev_date):
              $label = $msg_date===date('Y-m-d') ? 'Astăzi'
                     : ($msg_date===date('Y-m-d',strtotime('-1 day')) ? 'Ieri'
                     : date('d M Y', strtotime($m['created_at'])));
      ?>
      <div class="date-sep"><?= $label ?></div>
      <?php $prev_date=$msg_date; $is_grp=false; endif;
            $mimg = vchat_img($m['image_path'] ?? ''); ?>
      <div class="msg-row <?= $is_mine?'mine':'theirs' ?> <?= $is_grp?'grp':'' ?>" data-id="<?= (int)$m['id'] ?>">
        <?php if(!$is_mine): ?>
        <img class="msg-av <?= $last_of_grp?'':'hide' ?>" src="<?= h(avatar_url($m['sender_avatar']??null,$m['sender_name'])) ?>" alt="">
        <?php endif; ?>
        <div class="msg-stack">
          <div class="msg-bubble<?= $mimg ? ' has-img' : '' ?>">
            <?php if($mimg): ?><img class="msg-img" src="<?= h($mimg) ?>" alt="imagine" loading="lazy" onclick="vLightbox(this.src)"><?php endif; ?>
            <?php if(!empty($m['body'])): ?>
              <?php $mbody = html_entity_decode((string)$m['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
              <?php if($mimg): ?><div class="msg-cap"><?= nl2br(h($mbody)) ?></div>
              <?php else: ?><?= nl2br(h($mbody)) ?><?php endif; ?>
            <?php endif; ?>
          </div>
          <?php if($last_of_grp): ?>
          <div class="msg-meta">
            <span class="msg-time"><?= date('H:i', strtotime($m['created_at'])) ?></span>
            <?php if($is_mine): ?><span class="msg-status"><?= vmsg_check($m['is_read']) ?></span><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <?php if (empty($messages)): ?>
      <div class="chat-empty" style="padding:40px 20px;">
        <div class="em-ic">👋</div>
        <p style="font-size:14px;font-weight:600;color:var(--text);">Trimite primul mesaj lui <?= h($active_conv['other_name']) ?></p>
        <p style="font-size:12.5px;margin-top:4px;">Folosește un mesaj rapid, scrie sau atașează o poză.</p>
      </div>
      <?php endif; ?>
    </div>

    <button class="scroll-fab" id="scroll-fab" aria-label="Mergi jos">
      <span class="fab-dot"></span>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
    </button>

    <div class="quick-replies" id="quick-replies">
      <?php foreach ([
        'Bună! Mai e disponibil?','Care e prețul final?','Accepți negociere?',
        'Când îl pot vedea?','Poți trimite mai multe poze?','Mulțumesc!'
      ] as $qr): ?>
      <button type="button" class="qr-chip"><?= h($qr) ?></button>
      <?php endforeach; ?>
    </div>

    <div class="img-preview-bar" id="img-preview-bar">
      <img id="ipb-thumb" src="" alt="">
      <span class="ipb-txt">Imagine pregătită — scrie un mesaj (opțional) și trimite.</span>
      <div class="img-preview-x" id="ipb-cancel" title="Anulează">✕</div>
    </div>

    <div class="chat-input-wrap">
      <input type="file" id="chat-file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
      <button class="attach-btn" id="attach-btn" aria-label="Atașează imagine">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.4 11.1l-9.2 9.2a5 5 0 0 1-7.1-7.1l9.2-9.2a3.34 3.34 0 0 1 4.7 4.7l-9.2 9.2a1.67 1.67 0 0 1-2.4-2.4l8.5-8.5"/></svg>
      </button>
      <textarea class="chat-input" id="chat-input" placeholder="Scrie un mesaj…" maxlength="2000" rows="1"></textarea>
      <button class="chat-send" id="chat-send" aria-label="Trimite" disabled>
        <svg width="19" height="19" viewBox="0 0 24 24" fill="currentColor"><path d="M3.4 20.4l17.4-7.5c.8-.4.8-1.5 0-1.9L3.4 3.6c-.7-.3-1.4.2-1.4.9L2 9.1c0 .5.4 1 .9 1.1L17 12 2.9 13.8c-.5.1-.9.5-.9 1.1l0 4.6c0 .7.7 1.2 1.4.9z"/></svg>
      </button>
    </div>

    <?php else: ?>
    <div class="chat-empty">
      <div class="em-ic">💬</div>
      <p style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:6px;">Selectează o conversație</p>
      <p style="font-size:13px;">sau contactează un vânzător dintr-un anunț</p>
    </div>
    <?php endif; ?>
  </main>

</div>
<?php endif; ?>

<div class="chat-lightbox" id="chat-lightbox">
  <span class="lb-x">✕</span>
  <img id="lb-img" src="" alt="">
</div>

<script>
window.vLightbox = function(src){
  var lb = document.getElementById('chat-lightbox');
  var im = document.getElementById('lb-img');
  if(lb && im){ im.src = src; lb.classList.add('show'); }
};
(function(){
  var lb = document.getElementById('chat-lightbox');
  if(lb) lb.addEventListener('click', function(){ lb.classList.remove('show'); });
})();
</script>

<?php if ($active_conv):
  $last = end($messages);
  $last_id = $last ? (int)$last['id'] : 0;
?>
<script>
(function(){
  const CONV_ID = <?= (int)$conv_id ?>, MY_ID = <?= (int)$uid ?>;
  let lastId = <?= $last_id ?>, sending = false, tmpSeq = 0, pendingFile = null, pendingUrl = null;
  const inp     = document.getElementById('chat-input');
  const sendBtn = document.getElementById('chat-send');
  const msgs    = document.getElementById('chat-msgs');
  const fab     = document.getElementById('scroll-fab');
  const fileIn  = document.getElementById('chat-file');
  const pBar    = document.getElementById('img-preview-bar');
  const pThumb  = document.getElementById('ipb-thumb');

  const CK_SENT='<svg viewBox="0 0 16 16" class="ck" width="13" height="11"><path d="M2 8l4 4 8-9" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  const CK_READ='<svg viewBox="0 0 24 16" class="ck ck-read" width="16" height="11"><path d="M1 8l4 4 9-10" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 12l1.6 1.6L21 2" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  const CK_WAIT='<svg viewBox="0 0 24 24" class="ck" width="11" height="11"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2.6"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg>';

  function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');}
  function ckFor(s){return s==='sending'?CK_WAIT:s==='read'?CK_READ:CK_SENT;}
  function atBottom(){return msgs?msgs.scrollHeight-msgs.scrollTop-msgs.clientHeight<70:true;}
  function scrollBottom(sm){if(msgs)msgs.scrollTo({top:msgs.scrollHeight,behavior:sm?'smooth':'auto'});}
  function refreshSendState(){ sendBtn.disabled = (inp.value.trim()==='' && !pendingFile); }

  function buildRow(id, body, mine, status, imageUrl){
    const row=document.createElement('div');
    row.className='msg-row '+(mine?'mine':'theirs'); row.dataset.id=id;
    let inner=''; if(!mine) inner+='<img class="msg-av" src="" alt="" style="visibility:hidden">';
    const time=new Date().toLocaleTimeString('ro-RO',{hour:'2-digit',minute:'2-digit'});
    let bubble;
    if(imageUrl){
      bubble='<div class="msg-bubble has-img"><img class="msg-img" src="'+imageUrl+'" onclick="vLightbox(this.src)">'+
             (body?'<div class="msg-cap">'+esc(body)+'</div>':'')+'</div>';
    } else {
      bubble='<div class="msg-bubble">'+esc(body)+'</div>';
    }
    inner+='<div class="msg-stack">'+bubble+
           '<div class="msg-meta"><span class="msg-time">'+time+'</span>'+
           (mine?'<span class="msg-status '+(status||'')+'">'+ckFor(status)+'</span>':'')+
           '</div></div>';
    row.innerHTML=inner; return row;
  }

  function appendIncoming(m){
    if(msgs.querySelector('[data-id="'+m.id+'"]')) return;
    const empty=msgs.querySelector('.chat-empty'); if(empty) empty.remove();
    const mine=m.sender_id==MY_ID;
    msgs.appendChild(buildRow(m.id,m.body,mine,mine?(m.is_read?'read':'sent'):null,m.image_url||null));
    if(m.id>lastId) lastId=m.id;
  }

  function applyReadReceipts(upto){
    if(!upto) return;
    msgs.querySelectorAll('.msg-row.mine').forEach(function(r){
      const id=parseInt(r.dataset.id,10);
      if(id && id<=upto){
        const st=r.querySelector('.msg-status');
        if(st && !st.classList.contains('read-done')){
          st.className='msg-status read read-done'; st.innerHTML=CK_READ;
        }
      }
    });
  }

  function clearPending(){
    pendingFile=null;
    if(pendingUrl){ URL.revokeObjectURL(pendingUrl); pendingUrl=null; }
    fileIn.value=''; pBar.classList.remove('show'); refreshSendState();
  }

  async function send(text){
    text=(text!=null?text:inp.value).trim();
    const hasImg=!!pendingFile;
    if((!text && !hasImg) || sending) return;

    const srcFile = pendingFile;
    inp.value=''; inp.style.height='auto';
    const empty=msgs.querySelector('.chat-empty'); if(empty) empty.remove();
    const tmpId='tmp'+(++tmpSeq);
    const localUrl=hasImg?URL.createObjectURL(srcFile):null;
    const row=buildRow(tmpId,text,true,'sending',localUrl);
    msgs.appendChild(row); scrollBottom(true);
    sending=true; sendBtn.disabled=true;
    if(hasImg) clearPending();

    let req;
    if(hasImg){
      let fileToSend=srcFile;
      if(window.VeedoCompress){
        try{ fileToSend=await window.VeedoCompress.compress(srcFile,{maxDim:1600,quality:0.82}); }catch(e){}
      }
      const fd=new FormData();
      fd.append('conv_id',CONV_ID); fd.append('message',text); fd.append('image',fileToSend);
      req=fetch('/api/chat.php',{method:'POST',credentials:'same-origin',body:fd});
    } else {
      req=fetch('/api/chat.php',{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({conv_id:CONV_ID,message:text})});
    }
    req.then(function(r){return r.json();})
      .then(function(d){
        if(d && d.message){
          row.dataset.id=d.message.id;
          if(d.message.id>lastId) lastId=d.message.id;
          var st=row.querySelector('.msg-status');
          st.className='msg-status'; st.innerHTML=CK_SENT;
        } else { markError(row,text,localUrl); }
      })
      .catch(function(){ markError(row,text,localUrl); })
      .finally(function(){ sending=false; refreshSendState(); inp.focus(); });
  }
  function markError(row,text,imgUrl){
    var st=row.querySelector('.msg-status');
    if(st){ st.className='msg-status error'; st.innerHTML='⚠'; }
    row.style.cursor='pointer'; row.title='Apasă pentru a reîncerca';
    row.onclick=function(){
      row.remove();
      // text-only retry (imaginea trebuie reatasata manual)
      if(text) send(text);
    };
  }

  function poll(){
    fetch('/api/chat.php?conv_id='+CONV_ID+'&last_id='+lastId,{credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d) return;
        const stick=atBottom();
        if(d.messages && d.messages.length){
          d.messages.forEach(appendIncoming);
          if(stick) scrollBottom(true); else fab.classList.add('has-new');
        }
        applyReadReceipts(d.my_read_upto);
      }).catch(function(){});
  }

  // ── wire up ──
  if(inp){
    inp.addEventListener('input',function(){
      this.style.height='auto';
      this.style.height=Math.min(this.scrollHeight,120)+'px';
      refreshSendState();
    });
    inp.addEventListener('keydown',function(e){
      if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); send(); }
    });
  }
  sendBtn && sendBtn.addEventListener('click',function(){ send(); });

  document.querySelectorAll('#quick-replies .qr-chip').forEach(function(c){
    c.addEventListener('click',function(){ send(c.textContent.trim()); });
  });

  // ── atasare imagine ──
  document.getElementById('attach-btn').addEventListener('click',function(){ fileIn.click(); });
  fileIn.addEventListener('change',function(){
    const f=fileIn.files && fileIn.files[0];
    if(!f) return;
    if(!/^image\//.test(f.type)){ alert('Te rog alege un fișier imagine.'); fileIn.value=''; return; }
    if(f.size>6*1024*1024){ alert('Imaginea e prea mare (max 6MB).'); fileIn.value=''; return; }
    if(pendingUrl) URL.revokeObjectURL(pendingUrl);
    pendingFile=f; pendingUrl=URL.createObjectURL(f);
    pThumb.src=pendingUrl; pBar.classList.add('show'); refreshSendState(); inp.focus();
  });
  document.getElementById('ipb-cancel').addEventListener('click',clearPending);

  if(fab){
    fab.addEventListener('click',function(){ scrollBottom(true); fab.classList.remove('has-new'); });
  }
  msgs && msgs.addEventListener('scroll',function(){
    const up=msgs.scrollHeight-msgs.scrollTop-msgs.clientHeight>240;
    fab.classList.toggle('show',up);
    if(!up) fab.classList.remove('has-new');
  },{passive:true});

  scrollBottom(false);
  setInterval(poll,3000);
})();
</script>
<?php endif; ?>
