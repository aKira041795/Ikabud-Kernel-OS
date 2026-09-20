<?php
declare(strict_types=1);
$root=dirname(__DIR__); $_SERVER['HTTP_HOST']='akiracms.test'; $_SERVER['REQUEST_URI']='/'; require $root.'/bootstrap.php'; require_once $root.'/src/helpers/module-manager.php'; require_once $root.'/modules/cms-akira/cms-akira-builder/helpers.php'; require_once $root.'/modules/cms-akira/cms-akira-theme/helpers.php'; require_once $root.'/modules/cms-akira/cms-akira-core/helpers/capabilities.php';
app()->tenant()->setTenantId(54); kernel_request_context_set('tenant_id',54); kernel_request_context_delete('_tenant_module_settings_cache'); app()->setUser(['id'=>1,'role'=>'admin','username'=>'charlienacario884']);
$r=app()->capabilities(); foreach ([['cms-akira-core',cms_akira_core_capability_handlers()],['cms-akira-theme',cms_akira_theme_capability_handlers()],['cms-akira-builder',cms_akira_builder_capability_handlers()]] as [$m,$hs]) foreach($hs as $id=>$h) if(!$r->has($id)) $r->register($id,$m,static fn(mixed $p,string $c='',string $pr=''):mixed=>moduleWithContext($m,static fn():mixed=>$h($p,$c,$pr)),50,['first'],str_contains($id,'create@1')||str_contains($id,'publish@1')||str_contains($id,'delete@1')||str_contains($id,'unpublish@1')?['requires_protocol'=>'v2']:[]);
$was=app()->entityAuthority()->isAuthoritative('post','cms-akira-core'); if(!$was) app()->entityAuthority()->registerAuthority('post','cms-akira-core',['authority'=>true]); echo "CLI_AUTHORITY_WAS=".($was?'true':'false')."\n";
$call=static fn(string $id,array $p,string $m)=>app()->cap()->call($id,$p,['caller'=>['module'=>$m,'user'=>app()->user()],'mode'=>'first']);
$slug='t4a-page'; $u=bin2hex(random_bytes(5));
try {
 try {$post=$call('akira.post.create@1',['idempotency_key'=>"t4a-$u-post-create",'slug'=>$slug,'title'=>'T4a Composed Page','content'=>'Composition host post'],'cms-akira-core'); echo 'POST_CREATE='.json_encode($post)."\n";} catch(Throwable $ignored) { echo "POST_CREATE=existing-after-prior-attempt\n"; }
 $st=app()->db()->prepare('SELECT updated_at FROM cms_akira_posts WHERE tenant_id=? AND slug=?');$st->execute([54,$slug]);$updated=(string)$st->fetchColumn();
 $pub=$call('akira.post.publish@1',['idempotency_key'=>"t4a-$u-post-publish",'slug'=>$slug,'expected_updated_at'=>$updated],'cms-akira-core'); echo 'POST_PUBLISH='.json_encode($pub)."\n";
 $tree=['version'=>1,'blocks'=>[['block'=>'hero','props'=>['eyebrow'=>'CMS Akira','title'=>'Composed Page','subtitle'=>'Theme rendered','cta_label'=>'Learn more','cta_href'=>'/posts']],['block'=>'card-grid','props'=>['title'=>'Featured cards','items'=>[['title'=>'One','text'=>'First card','href'=>'/one'],['title'=>'Two','text'=>'Second card','href'=>'/two']]]]]];
 $create=$call('akira.builder.create@1',['idempotency_key'=>"t4a-$u-builder-create",'entity_type'=>'post','entity_key'=>$slug,'title'=>'T4a Composed Page','tree'=>$tree],'cms-akira-builder'); echo 'COMPOSITION_CREATE='.json_encode($create)."\n";
 $pk="t4a-$u-builder-publish"; $cp=$call('akira.builder.publish@1',['idempotency_key'=>$pk,'entity_type'=>'post','entity_key'=>$slug],'cms-akira-builder'); echo 'COMPOSITION_PUBLISH='.json_encode($cp)."\n";
 $replay=$call('akira.builder.publish@1',['idempotency_key'=>$pk,'entity_type'=>'post','entity_key'=>$slug],'cms-akira-builder'); echo 'PUBLISH_REPLAY='.json_encode($replay)."\n";
 $q=app()->db()->prepare("SELECT COUNT(*) FROM audit_logs WHERE module='cms-akira-builder' AND action='akira.builder.publish' AND entity_id='post:t4a-page'");$q->execute();echo 'PUBLISH_AUDITS='.$q->fetchColumn()."\n";
 echo "READY_FOR_HTTP u=$u\n";
} catch(Throwable $e){echo 'FAIL='.$e::class.':'.$e->getMessage()."\n";for($x=$e->getPrevious();$x;$x=$x->getPrevious())echo 'CAUSE='.$x::class.':'.$x->getMessage()."\n";exit(1);} 
