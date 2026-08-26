<?php
require_once __DIR__ . '/../../app/config/init.php'; require_login(); require_role('Administrator','Time Keeper'); header('Content-Type: application/json');
$db=db(); $input=json_decode(file_get_contents('php://input'),true)?:[];
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals((string)csrf_token(),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Invalid CSRF token.']);exit;}
 $id=(int)($input['trainee_id']??0);$descriptor=$input['descriptor']??null;
 if($id<=0||!is_array($descriptor)||count($descriptor)!==128){http_response_code(422);echo json_encode(['ok'=>false,'message'=>'A valid face descriptor is required.']);exit;}
 $json=json_encode(array_map('floatval',$descriptor));$userId=current_user_id();$stmt=$db->prepare('UPDATE dtr_trainees SET face_descriptor=?,updated_by=? WHERE id=? AND is_active=1');$stmt->bind_param('sii',$json,$userId,$id);$ok=$stmt->execute()&&$stmt->affected_rows>=0;$stmt->close();echo json_encode(['ok'=>$ok,'message'=>$ok?'Face enrolled successfully.':'Unable to save face descriptor.']);exit;
}
$result=[];$stmt=$db?$db->prepare("SELECT id,first_name,middle_name,last_name,face_descriptor FROM dtr_trainees WHERE is_active=1 AND face_descriptor IS NOT NULL"):null;if($stmt){$stmt->execute();$rows=$stmt->get_result();while($row=$rows->fetch_assoc()){$result[]=['trainee_id'=>(int)$row['id'],'name'=>trim($row['first_name'].' '.$row['middle_name'].' '.$row['last_name']),'descriptor'=>json_decode($row['face_descriptor'],true)];}$stmt->close();}echo json_encode($result);
