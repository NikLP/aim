<?php

// Creates temp roles t_staff/t_manager, role scopes, users t_alice/t_bob/t_mgr/t_carol and the manager-sees-staff matrix. See role-scope-retest.mjs.
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\simple_oauth\Entity\Oauth2Scope;
$perms = ['access mcp server','recall aim facts','remember aim facts','create user aim facts','create site aim facts'];
foreach (['t_staff','t_manager'] as $r) { $role = Role::create(['id'=>$r,'label'=>$r]); foreach ($perms as $p) $role->grantPermission($p); $role->save(); }
foreach (['t_staff','t_manager'] as $r) {
  Oauth2Scope::create(['id'=>"t_role_$r",'name'=>"t:$r",'description'=>'temp','grant_types'=>['authorization_code'=>['status'=>true],'refresh_token'=>['status'=>true]],'umbrella'=>false,'parent'=>null,'granularity_id'=>'role','granularity_configuration'=>['role'=>$r]])->save();
}
$users = ['t_alice'=>['t_staff'],'t_bob'=>['t_staff'],'t_mgr'=>['t_manager'],'t_carol'=>[]];
foreach ($users as $n=>$roles) { $u = User::create(['name'=>$n,'mail'=>"$n@example.invalid",'status'=>1,'roles'=>$roles]); $u->save(); echo "$n uid ".$u->id()."\n"; }
$c = \Drupal::configFactory()->getEditable('aim.settings'); $c->set('user_scope_role_visibility',['t_manager'=>['t_staff']])->save();
