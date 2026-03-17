<?php

session_start();
require_once '../config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'staff') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? 4;

try {
    $pdo = getDBConnection();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        
        switch ($_POST['action']) {
            case 'create_project':
                handleCreateProject($pdo, $user_id);
                break;
                
            case 'update_project':
                handleUpdateProject($pdo);
                break;
                
            case 'get_project_details':
                handleGetProjectDetails($pdo);
                break;
                
            case 'delete_project':
                handleDeleteProject($pdo);
                break;
                
            case 'create_task':
                handleCreateTask($pdo);
                break;
                
            case 'update_task_progress':
                handleUpdateTaskProgress($pdo);
                break;
                
            case 'create_milestone':
                handleCreateMilestone($pdo);
                break;
                
            case 'update_milestone':
                handleUpdateMilestone($pdo);
                break;
                
            case 'allocate_resource':
                handleAllocateResource($pdo);
                break;
                
            case 'get_project_tasks':
                handleGetProjectTasks($pdo);
                break;
                
            case 'get_project_resources':
                handleGetProjectResources($pdo);
                break;
                
            default:
                throw new Exception('Invalid action specified');
        }
        
    } else {
        throw new Exception('Invalid request method or missing action');
    }
    
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleCreateProject($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO projects (project_code, project_name, description, client_name, 
                                start_date, expected_end_date, status, priority, budget, location, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $_POST['project_code'],
            $_POST['project_name'],
            $_POST['description'] ?? '',
            $_POST['client_name'] ?? '',
            $_POST['start_date'],
            $_POST['expected_end_date'],
            $_POST['status'] ?? 'planning',
            $_POST['priority'] ?? 'medium',
            $_POST['budget'] ?? 0,
            $_POST['location'] ?? '',
            $user_id
        ]);
        
        if ($result) {
            $project_id = $pdo->lastInsertId();
            echo json_encode([
                'success' => true,
                'project_id' => $project_id,
                'message' => 'Project created successfully'
            ]);
        } else {
            throw new Exception('Failed to create project');
        }
        
    } catch(PDOException $e) {
        if ($e->getCode() == 23000) {
            throw new Exception('Project code already exists');
        }
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

function handleUpdateProject($pdo) {
    try {
        $stmt = $pdo->prepare("
            UPDATE projects SET project_name=?, description=?, client_name=?, 
                              expected_end_date=?, status=?, priority=?, budget=?, 
                              location=?, progress_percentage=? 
            WHERE id=?
        ");
        
        $result = $stmt->execute([
            $_POST['project_name'],
            $_POST['description'] ?? '',
            $_POST['client_name'] ?? '',
            $_POST['expected_end_date'],
            $_POST['status'],
            $_POST['priority'],
            $_POST['budget'] ?? 0,
            $_POST['location'] ?? '',
            $_POST['progress_percentage'] ?? 0,
            $_POST['project_id']
        ]);
        
        echo json_encode([
            'success' => $result,
            'message' => $result ? 'Project updated successfully' : 'Failed to update project'
        ]);
        
    } catch(PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

function handleGetProjectDetails($pdo) {
    try {
        $project_id = $_POST['project_id'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT p.*, 
                   (SELECT COUNT(*) FROM project_tasks WHERE project_id = p.id) as task_count,
                   (SELECT COUNT(*) FROM project_tasks WHERE project_id = p.id AND status = 'completed') as completed_tasks,
                   (SELECT COUNT(*) FROM project_milestones WHERE project_id = p.id) as milestone_count,
                   (SELECT COUNT(*) FROM project_resources WHERE project_id = p.id) as resource_count
            FROM projects p 
            WHERE p.id = ?
        ");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$project) {
            throw new Exception('Project not found');
        }
        
        $stmt = $pdo->prepare("
            SELECT * FROM project_tasks 
            WHERE project_id = ? 
            ORDER BY created_at DESC 
            LIMIT 5
        ");
        $stmt->execute([$project_id]);
        $recent_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("
            SELECT * FROM project_milestones 
            WHERE project_id = ? 
            ORDER BY due_date ASC
        ");
        $stmt->execute([$project_id]);
        $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'project' => $project,
            'recent_tasks' => $recent_tasks,
            'milestones' => $milestones
        ]);
        
    } catch(PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

function handleDeleteProject($pdo) {
    try {
        $project_id = $_POST['project_id'] ?? 0;
        
        $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
        $result = $stmt->execute([$project_id]);
        
        echo json_encode([
            'success' => $result,
            'message' => $result ? 'Project deleted successfully' : 'Failed to delete project'
        ]);
        
    } catch(PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

function handleCreateTask($pdo) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO project_tasks (project_id, task_name, description, start_date, 
                                     due_date, status, priority, estimated_hours) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $_POST['project_id'],
            $_POST['task_name'],
            $_POST['description'] ?? '',
            $_POST['start_date'],
            $_POST['due_date'],
            $_POST['status'] ?? 'pending',
            $_POST['priority'] ?? 'medium',
            $_POST['estimated_hours'] ?? 0
        ]);
        
        if ($result) {
            $task_id = $pdo->lastInsertId();
            echo json_encode([
                'success' => true,
                'task_id' => $task_id,
                'message' => 'Task created successfully'
            ]);
        } else {
            throw new Exception('Failed to create task');
        }
        
    } catch(PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

function handleUpdateTaskProgress($pdo) {
    try {
        $progress = $_POST['progress_percentage'] ?? 0;
        $completion_date = ($progress == 100) ? date('Y-m-d') : null;
        $status = ($progress == 100) ? 'completed' : (($progress > 0) ? 'in_progress' : 'pending');
        
        $stmt = $pdo->prepare("
            UPDATE project_tasks SET progress_percentage=?, actual_hours=?, 
                                   status=?, completion_date=? 
            WHERE id=?
        ");
        
        $result = $stmt->execute([
            $progress,
            $_POST['actual_hours'] ?? 0,
            $status,
            $completion_date,
            $_POST['task_id']
        ]);
        
        echo json_encode([
            'success' => $result,
            'message' => $result ? 'Task progress updated successfully' : 'Failed to update task'
        ]);
        
    } catch(PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

function handleGetProjectTasks($pdo) {
    try {
        $project_id = $_POST['project_id'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT * FROM project_tasks 
            WHERE project_id = ? 
            ORDER BY due_date ASC
        ");
        $stmt->execute([$project_id]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'tasks' => $tasks
        ]);
        
    } catch(PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

function handleCreateMilestone($pdo) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO project_milestones (project_id, milestone_name, description, due_date, deliverables) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $_POST['project_id'],
            $_POST['milestone_name'],
            $_POST['description'] ?? '',
            $_POST['due_date'],
            $_POST['deliverables'] ?? ''
        ]);
        
        if ($result) {
            $milestone_id = $pdo->lastInsertId();
            echo json_encode([
                'success' => true,
                'milestone_id' => $milestone_id,
                'message' => 'Milestone created successfully'
            ]);
        } else {
            throw new Exception('Failed to create milestone');
        }
        
    } catch(PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

function handleUpdateMilestone($pdo) {
    try {
        $completion_date = null;
        $status = $_POST['status'] ?? 'pending';
        
        if ($status === 'completed') {
            $completion_date = date('Y-m-d');
        }
        
        $stmt = $pdo->prepare("
            UPDATE project_milestones 
            SET status=?, completion_date=?, completion_notes=? 
            WHERE id=?
        ");
        
        $result = $stmt->execute([
            $status,
            $completion_date,
            $_POST['completion_notes'] ?? '',
            $_POST['milestone_id']
        ]);
        
        echo json_encode([
            'success' => $result,
            'message' => $result ? 'Milestone updated successfully' : 'Failed to update milestone'
        ]);
        
    } catch(PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

function handleAllocateResource($pdo) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO project_resources (project_id, resource_type, resource_name, 
                                         quantity_required, quantity_allocated, unit_cost, 
                                         allocation_date, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $_POST['project_id'],
            $_POST['resource_type'] ?? 'inventory_item',
            $_POST['resource_name'],
            $_POST['quantity_required'] ?? 1,
            $_POST['quantity_allocated'] ?? 0,
            $_POST['unit_cost'] ?? 0,
            $_POST['allocation_date'] ?? date('Y-m-d'),
            $_POST['status'] ?? 'planned'
        ]);
        
        if ($result) {
            $resource_id = $pdo->lastInsertId();
            echo json_encode([
                'success' => true,
                'resource_id' => $resource_id,
                'message' => 'Resource allocated successfully'
            ]);
        } else {
            throw new Exception('Failed to allocate resource');
        }
        
    } catch(PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

function handleGetProjectResources($pdo) {
    try {
        $project_id = $_POST['project_id'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT * FROM project_resources 
            WHERE project_id = ? 
            ORDER BY allocation_date DESC
        ");
        $stmt->execute([$project_id]);
        $resources = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'resources' => $resources
        ]);
        
    } catch(PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}
?>