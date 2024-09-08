<?php

namespace app\controllers;
use Yii;
use yii\filters\auth\CompositeAuth;
use yii\filters\AccessControl;
use app\models\Expense;
use app\models\ExpenseCategory;
use yii\data\ActiveDataProvider;
use yii\data\Pagination;
use DateTime;
use Firebase\JWT\JWT;
use yii\db\Query;
use yii\web\UploadedFile;
use app\models\Customer;
use app\models\Events;
use app\models\EventParticipants;
use yii\web\Response;
use Intervention\Image\ImageManagerStatic as Image;

class BudgetController extends \yii\web\Controller
{   

    public function __construct($id, $module, $config = [])
    {
        parent::__construct($id, $module, $config);

        $headers = Yii::$app->request->headers;
        if ($headers->has('Authorization')) {
            $authorizationHeader = $headers->get('Authorization');
            $token = str_replace('Bearer ', '', $authorizationHeader);
            $user = Customer::find()->where(['authKey' => $token])->one();
            
            if (!$user) {
                Yii::$app->response->statusCode = 401;
                return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
            }
        } else {
            Yii::$app->response->statusCode = 401;
            return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
        }
    }


    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['corsFilter'] = [
            'class' => \yii\filters\Cors::class,
            'cors' => [
                'Origin' => ['http://localhost:4200', 'https://secure.walletplus.in', 'https://walletplus.in'],
                'Access-Control-Request-Method' => ['FETCH','GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                'Access-Control-Allow-Credentials' => true,
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Max-Age' => 86400,
            ],
        ];
        return $behaviors;
    }



    public function actionSendEmail($to, $emailType, $extraParams)
    {
        // 1: Welcome Email
        // 2: Credentials 
        // 3: Email Verfication 

        $templateQuery = Yii::$app->db->createCommand("select * from bt_email_templates where id_email_template = 3");
        $templateData = $templateQuery->queryOne();

        // print_r(templateData);
        // exit;


        $emailQuery = Yii::$app->db->createCommand("select * from bt_email where id_email = ".$emailType);
        $email = $emailQuery->queryOne();

       

        $from = $email['from_email']; 
        $fromName = $email['from_name'];
        $subject = $email['subject'];    
        
        $htmlContent = str_replace("template_email_content" , $email['email_content'], $templateData['email_template']);

        $subjectContent = '<tr> <td align="center" style="font-size:18px;color:#f90;font-family:helvetica,arial,sans-serif">'.$subject.'</td></tr>';
        $htmlContent = str_replace("template_subject_content" , $subjectContent, $htmlContent);

        $text = 'report';
        $htmlContent = str_replace("template_button_content" , $text, $htmlContent);

        
        try {
            $headers = "MIME-Version: 1.0" . "\r\n"; 
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n"; 
            $headers .= 'From: '.$fromName.'<'.$from.'>' . "\r\n"; 

            if($email['cc_email']){
                $headers .= 'Cc: '.$email['cc_email'] . "\r\n";  
            }
           

            if(mail($to, $subject, $htmlContent, $headers)){ 
                return true;
            }else{ 
                return false;
            }
        } catch (Exception $e) {
            echo "Email could not be sent. Error: {$mailer->ErrorInfo}";
        }
    }

    public function actionCategoryList()
    {

        $headers = Yii::$app->request->headers;
        if ($headers->has('Authorization')) {
            $authorizationHeader = $headers->get('Authorization');
            $token = str_replace('Bearer ', '', $authorizationHeader);
            $user = Customer::find()->where(['authKey' => $token])->one();
            

            if($user){
                $rawBody = Yii::$app->request->rawBody;
                $data = json_decode($rawBody, true);
                $id_type = 2;
        
                switch ($data['type']) {
                    case 'expense': $id_type = 2;
                        break;
                    case 'savings': $id_type = 1;
                        break;
                    case 'income': $id_type = 3;
                        break;
                }
        
                $categories = ExpenseCategory::find()
                    ->where(['id_type' => $id_type])
                    ->orderBy(['category_name' => SORT_ASC])
                    ->all();
                $response['list'] = $categories;
                $response['categoryImagePath'] = 'https://walletplus.in/category/';
                $response['imagePath'] = 'https://walletplus.in/expenses/';

                Yii::$app->response->statusCode = 200;
                return \yii\helpers\Json::encode($response);  
            } else {
                Yii::$app->response->statusCode = 401;
                return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
            }
        } else {
            Yii::$app->response->statusCode = 401;
            return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
        }
    }

    public function actionSendReport(){
        $this->actionSendEmail('abdulfareed.md@gmail.com', '9', NULL);
    }

    public function actionGetList()
    {
        $headers = Yii::$app->request->headers;
        if ($headers->has('Authorization')) {
            $authorizationHeader = $headers->get('Authorization');
            $token = str_replace('Bearer ', '', $authorizationHeader);
            $user = Customer::find()->where(['authKey' => $token])->one();
            if($user){           

                $rawBody = Yii::$app->request->rawBody;
                $data = json_decode($rawBody, true);

                $query = (new Query())
                    ->select('*')
                    ->from('bt_expense')
                    ->leftJoin('bt_category category', 'category.id_category = bt_expense.id_category')
                    ->leftJoin('bt_type type', 'type.id_type = category.id_type');

                if ($data['type'] == 0) {
                    $query->where(['bt_expense.id_customer' => $user->id]);
                } else {
                    $query->where(['type.id_type' => $data['type'], 'bt_expense.id_customer' => $user->id]);

                    if (isset($data['queryParam'])) {
                        $query->andWhere(['OR',
                            ['like', 'bt_expense.expense_name', $data['queryParam']],
                            ['like', 'bt_expense.description', $data['queryParam']]
                        ]);
                    }
                    if (isset($data['category'])) {
                        $query->andWhere(['bt_expense.id_category' => $data['category']]);
                    }
                }
                $query->orderBy(['bt_expense.id_expense' => SORT_DESC]);

                

                $command = $query->createCommand();
                $list = $command->queryAll();
                $response['list'] = $list;
                $response['categoryImagePath'] = 'https://walletplus.in/category/';
                $response['imagePath'] = 'https://walletplus.in/expenses/';
                $response['expenseImagePath'] = 'https://walletplus.in/expenses/';
                $response['userImagePath'] = 'https://walletplus.in/users/';
                
                
                Yii::$app->response->statusCode = 200;
                return \yii\helpers\Json::encode($response);
                
            } else {
                Yii::$app->response->statusCode = 401;
                return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
            }
        }else{
            Yii::$app->response->statusCode = 401;
            return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
        }



        
    }


    public function actionGet()
    {
        // exit("here");
        $headers = Yii::$app->request->headers;
        if ($headers->has('Authorization')) {
            $authorizationHeader = $headers->get('Authorization');
            $token = str_replace('Bearer ', '', $authorizationHeader);
            $user = Customer::find()->where(['authKey' => $token])->one();
            if($user){
                $id = Yii::$app->request->get('id');
                $query = (new Query())
                ->select('*')
                ->from('bt_expense')
                ->where(['bt_expense.id_expense' => $id]);
                
                $command = $query->createCommand();
                $data = $command->queryOne();
                $response['data'] = $data;
                $response['imagePath'] = 'http://localhost/walletplus/expenses/';
                Yii::$app->response->statusCode = 200;
                return \yii\helpers\Json::encode($response);
            } else {
                Yii::$app->response->statusCode = 401;
                return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
            }
        } else {
            Yii::$app->response->statusCode = 401;
            return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
        }
    }

    public function actionSuggestion()
    {

        $headers = Yii::$app->request->headers;
        if ($headers->has('Authorization')) {
            $authorizationHeader = $headers->get('Authorization');
            $token = str_replace('Bearer ', '', $authorizationHeader);
            $user = Customer::find()->where(['authKey' => $token])->one();
            if($user){
                $rawBody = Yii::$app->request->rawBody;
                $data = json_decode($rawBody, true);

                if (!empty($data['param'])) {
                    $filteredExpenses = Expense::find()
                    ->select(['expense_name', 'id_category'])
                    ->where(['LIKE', 'expense_name', $data['param'] . '%', false])
                    ->asArray() // Retrieve the results as an array
                    ->all();    // Retrieve all matching rows

                    // $filteredExpenses = array_unique($filteredExpenses, SORT_REGULAR);
                    $uniqueExpenses = [];
                    foreach ($filteredExpenses as $expense) {
                        $expenseName = $expense['expense_name'];
                        if (!isset($uniqueExpenses[$expenseName])) {
                            $uniqueExpenses[$expenseName] = $expense;
                        }
                    }
                    $filteredExpenses = array_values($uniqueExpenses);
                    
                    Yii::$app->response->format = Response::FORMAT_JSON;
                    Yii::$app->response->statusCode = 200;
                    return $filteredExpenses;
                } else {
                    Yii::$app->response->format = Response::FORMAT_JSON;
                    Yii::$app->response->statusCode = 400;
                    return ['error' => 'Missing or empty parameter'];
                }
            } else {
                Yii::$app->response->statusCode = 401;
                return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
            }
        } else {
            Yii::$app->response->statusCode = 401;
            return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
        }

    }

    public function actionStatistics()
    {

        $headers = Yii::$app->request->headers;
        if ($headers->has('Authorization')) {
            $authorizationHeader = $headers->get('Authorization');
            $token = str_replace('Bearer ', '', $authorizationHeader);
            $user = Customer::find()->where(['authKey' => $token])->one();
            if($user){
                $rawBody = Yii::$app->request->rawBody;
                $data = json_decode($rawBody, true);

                $userId =  $user->id;
                $date = date("Y/m/d");

                /** Month wise */
                $timestamp    = strtotime($date);
                $startDate = $data['startDate'];
                $endDate = $data['endDate'];

                $categories = array();
                // $categoriesQuery = Yii::$app->db->createCommand("select cat.category_name, exp.id_type, sum(exp.amount) as total from bt_expense exp, bt_category cat where cat.id_category=exp.id_category and exp.id_type=2 and exp.id_customer=".$userId." and (date_of_transaction BETWEEN '".$startDate."' AND '".$endDate."') group by exp.id_category ORDER BY total DESC;");

                $categoriesQuery = Yii::$app->db->createCommand("
                    SELECT 
                        cat.category_name, 
                        cat.category_image, 
                        exp.id_type, 
                        SUM(exp.amount) AS total 
                    FROM bt_expense exp
                    JOIN bt_category cat ON exp.id_category = cat.id_category
                    WHERE exp.id_type = 2 
                    AND exp.id_customer = ".$userId." 
                    AND (date_of_transaction BETWEEN '".$startDate."' AND '".$endDate."') 
                    GROUP BY exp.id_category 
                    ORDER BY total DESC;
                ");
                
                $categoryResults = $categoriesQuery->queryAll();
                foreach( $categoryResults as $row ) {
                    array_push($categories, $row);
                }

            
                
                $command = Yii::$app->db->createCommand("SELECT SUM(amount) FROM bt_expense WHERE (date_of_transaction BETWEEN '".$startDate."' AND '".$endDate."')");
                

                $expenseTotalQuery = Yii::$app->db->createCommand("SELECT SUM(amount) FROM bt_expense WHERE id_type=2 and id_customer=".$userId." and (date_of_transaction BETWEEN '".$startDate."' AND '".$endDate."')");
                $expenseTotal = $expenseTotalQuery->queryScalar();

                
                $incomeTotalQuery = Yii::$app->db->createCommand("SELECT SUM(amount) FROM bt_expense WHERE id_type=3 and id_customer=".$userId." and (date_of_transaction BETWEEN '".$startDate."' AND '".$endDate."')");
                $incomeTotal = $incomeTotalQuery->queryScalar();


                $expenseData = array();
                $expenseDataQuery = Yii::$app->db->createCommand("SELECT sum(exp.amount) as amount , exp.date_of_transaction FROM `bt_expense` exp where exp.id_type=2 and exp.id_customer=".$userId." and (date_of_transaction BETWEEN '".$startDate."' AND '".$endDate."') group by exp.date_of_transaction order by exp.date_of_transaction asc;
                ");
                
                $expenseDataQueryResults = $expenseDataQuery->queryAll();
                foreach( $expenseDataQueryResults as $row ) {
                    array_push($expenseData, $row);
                }
                
                $expenditureTotalQuery = Yii::$app->db->createCommand("SELECT SUM(amount) FROM bt_expense WHERE id_type=1 and id_customer=".$userId." and (date_of_transaction BETWEEN '".$startDate."' AND '".$endDate."')");
                $expenditureTotal = $expenditureTotalQuery->queryScalar();
                


                $response['categoryImagePath'] = 'https://walletplus.in/category/';
                $response['expenseTotal'] = ($expenseTotal)? $expenseTotal : 0 ;   
                $response['expenditureTotal'] = ($expenditureTotal)?$expenditureTotal:0;   
                $response['incomeTotal'] = ($incomeTotal)?$incomeTotal:0;
                $response['categories'] = $categories;
                $response['expenseData'] = $expenseData;

                return \yii\helpers\Json::encode($response);  
            }
            if (!$user) {
                Yii::$app->response->statusCode = 401;
                return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
            }
        } else {
            Yii::$app->response->statusCode = 401;
            return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
        }

    }


    public function actionAdd()
    {   
        $headers = Yii::$app->request->headers;
        if ($headers->has('Authorization')) {
            $authorizationHeader = $headers->get('Authorization');
            $token = str_replace('Bearer ', '', $authorizationHeader);
            $user = Customer::find()->where(['authKey' => $token])->one();
            
            if($user){
                
                $data = Yii::$app->request->post();
                $rawBody = Yii::$app->request->rawBody;
                $data = json_decode($rawBody, true);

                $id_type = 0;
                switch (Yii::$app->request->post('type')) {
                    case 'expense': $id_type = 2;
                        break;
                    case 'savings': $id_type = 1;
                        break;
                    case 'income': $id_type = 3;
                        break;
                }

                if ( $id_type == 0) {                    
                    Yii::$app->response->statusCode = 400;
                    return \yii\helpers\Json::encode(['error' => 'Invalid Expense Type']);
                }

                $newExpense = new Expense();
                $newExpense->id_type = $id_type;
                $newExpense->id_customer = $user->id;
                $newExpense->id_category = Yii::$app->request->post('category');
                $newExpense->expense_name =  Yii::$app->request->post('name'); 
                $newExpense->description = Yii::$app->request->post('description'); 
                $newExpense->amount = Yii::$app->request->post('amount'); 

                $dateOfTransaction = DateTime::createFromFormat('Y-m-d', Yii::$app->request->post('dateOfTransaction'));
                if ($dateOfTransaction) {
                    $newExpense->date_of_transaction = $dateOfTransaction->format('d/m/Y');
                } else {
                    Yii::$app->response->statusCode = 400;
                    return \yii\helpers\Json::encode(['error' => 'Invalid date format']);
                }

                // Handle the uploaded image
                $imageFile = UploadedFile::getInstanceByName('image');
                if ($imageFile) {
                    $uploadPath = Yii::getAlias('@webroot') . '/expenses/';
                    $imageName = time() . '_' . $imageFile->baseName . '.' . $imageFile->extension;
                    $imageFile->saveAs($uploadPath . $imageName);

                    

                    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
                    $fileExtensions = ['pdf'];
                    $imageFileExtension = strtolower($imageFile->extension);

                    if (in_array($imageFileExtension, $imageExtensions)) {

                        $image = Image::make($uploadPath . $imageName);
                        $image->encode('jpg', 70); 
                        $compressedImageName = 'wplus_' . $imageName;
                        $image->save($uploadPath . $compressedImageName);
                        $newExpense->image = $compressedImageName;

                    } else if (in_array($imageFileExtension, $fileExtensions)) {
                        $newExpense->image = $imageName;
                    } else {
                        Yii::$app->response->statusCode = 500;
                        return \yii\helpers\Json::encode(['error' => 'Unsupported image type. Please upload a JPG, PNG, GIF, BMP, or WebP file.']);
                    }

                }

                if ($newExpense->save()) {
                    Yii::$app->response->statusCode = 201; // Created status code
                    return \yii\helpers\Json::encode($newExpense);
                } else {
                    Yii::$app->response->statusCode = 422; // Unprocessable Entity status code
                    return \yii\helpers\Json::encode($newExpense->getErrors());
                }
            } else {
                Yii::$app->response->statusCode = 401;
                return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
            }
        } else {
            Yii::$app->response->statusCode = 401;
            return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
        }
        
    }

    public function actionUpdate()
    {   

        $headers = Yii::$app->request->headers;
        if ($headers->has('Authorization')) {
            $authorizationHeader = $headers->get('Authorization');
            $token = str_replace('Bearer ', '', $authorizationHeader);
            $user = Customer::find()->where(['authKey' => $token])->one();
            if($user){
                $id = Yii::$app->request->post('id');
                // $data = Yii::$app->request->post();

                $rawBody = Yii::$app->request->rawBody;
                $data = json_decode($rawBody, true);

                $id_type = 0;
                switch (Yii::$app->request->post('type')) {
                    case 'expense': $id_type = 2;
                        break;
                    case 'savings': $id_type = 1;
                        break;
                    case 'income': $id_type = 3;
                        break;
                }
                if ( $id_type == 0) {                    
                    Yii::$app->response->statusCode = 400;
                    return \yii\helpers\Json::encode(['error' => 'Invalid Expense Type']);
                }
                
                $newExpense = Expense::findOne($id);
                $newExpense->id_type = $id_type;
                $newExpense->id_customer = $user->id;
                $newExpense->id_category = Yii::$app->request->post('category');
                $newExpense->expense_name =  Yii::$app->request->post('name'); 
                $newExpense->description = Yii::$app->request->post('description'); 
                $newExpense->amount = Yii::$app->request->post('amount'); 

                $dateOfTransaction = DateTime::createFromFormat('Y-m-d', Yii::$app->request->post('dateOfTransaction'));
                if ($dateOfTransaction) {
                    $newExpense->date_of_transaction = $dateOfTransaction->format('d/m/Y');
                } else {
                    Yii::$app->response->statusCode = 400;
                    return \yii\helpers\Json::encode(['error' => 'Invalid date format']);
                }

                // Handle the uploaded image
                $imageFile = UploadedFile::getInstanceByName('image');
                if ($imageFile) {
                    $uploadPath = Yii::getAlias('@webroot') . '/expenses/';
                    $imageName = time() . '_' . $imageFile->baseName . '.' . $imageFile->extension;
                    $imageFile->saveAs($uploadPath . $imageName);

                    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
                    $fileExtensions = ['pdf'];
                    $imageFileExtension = strtolower($imageFile->extension);

                    if (in_array($imageFileExtension, $imageExtensions)) {

                        $image = Image::make($uploadPath . $imageName);
                        $image->encode('jpg', 70); 
                        $compressedImageName = 'wplus_' . $imageName;
                        $image->save($uploadPath . $compressedImageName);
                        $newExpense->image = $compressedImageName;

                    } else if (in_array($imageFileExtension, $fileExtensions)) {
                        $newExpense->image = $imageName;
                    } else {
                        Yii::$app->response->statusCode = 500;
                        return \yii\helpers\Json::encode(['error' => 'Unsupported image type. Please upload a JPG, PNG, GIF, BMP, or WebP file.']);
                    }
                }

                if ($newExpense->save()) {
                    Yii::$app->response->statusCode = 201; // Created status code
                    return \yii\helpers\Json::encode($newExpense);
                } else {
                    Yii::$app->response->statusCode = 422; // Unprocessable Entity status code
                    return \yii\helpers\Json::encode($newExpense->getErrors());
                }
            } else {
                Yii::$app->response->statusCode = 401;
                return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
            }
        } else {
            Yii::$app->response->statusCode = 401;
            return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
        }

        
    }


    public function actionDelete()
    {   

        $headers = Yii::$app->request->headers;
        if ($headers->has('Authorization')) {
            $authorizationHeader = $headers->get('Authorization');
            $token = str_replace('Bearer ', '', $authorizationHeader);
            $user = Customer::find()->where(['authKey' => $token])->one();
            if($user){
                $rawBody = Yii::$app->request->rawBody;
                $data = json_decode($rawBody, true);

                $expense = Expense::findOne($data['id']);

                if ($expense) {
                    // Delete the associated image file if it exists
                    if($expense->image){
                        $imagePath = Yii::getAlias('@webroot') . '/expenses/' . $expense->image;
                        if (file_exists($imagePath)) {
                            unlink($imagePath);
                        }    
                    }
            
                    if ($expense->delete()) {
                        Yii::$app->response->statusCode = 204; // No Content status code
                        return \yii\helpers\Json::encode(['message' => 'Expense deleted successfully']);
                    } else {
                        Yii::$app->response->statusCode = 500; // Internal Server Error status code
                        return \yii\helpers\Json::encode(['error' => 'Failed to delete expense']);
                    }
                } else {
                    Yii::$app->response->statusCode = 404; // Not Found status code
                    return \yii\helpers\Json::encode(['error' => 'Expense not found']);
                }
            } else {
                Yii::$app->response->statusCode = 401;
                return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
            }
        } else {
            Yii::$app->response->statusCode = 401;
            return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
        }

        
    }

    public function actionEvents()
    {
        $headers = Yii::$app->request->headers;
        if ($headers->has('Authorization')) {
            $authorizationHeader = $headers->get('Authorization');
            $token = str_replace('Bearer ', '', $authorizationHeader);
            $user = Customer::find()->where(['authKey' => $token])->one();
            
            if ($user) {
                $events = Events::find()
                    ->where(['id_customer' => $user->id])
                    ->orderBy(['id_event' => SORT_DESC])
                    ->all();

                $eventData = [];
                foreach ($events as $event) {
                    // Retrieve participants with names for each event
                    $participantsQuery = (new Query())
                        ->select(['bt_event_participants.id_customer', 'bt_customer.firstname', 'bt_customer.lastname'])
                        ->from('bt_event_participants')
                        ->leftJoin('bt_customer', 'bt_event_participants.id_customer = bt_customer.id')
                        ->where(['bt_event_participants.id_event' => $event->id_event]);

                    $participantsCommand = $participantsQuery->createCommand();
                    $participants = $participantsCommand->queryAll(); // Get all participant details
                    
                    // Format participants to include their names
                    $formattedParticipants = array_map(function($participant) {
                        return [
                            'id' => $participant['id_customer'],
                            'firstname' => $participant['firstname'],
                            'lastname' => $participant['lastname']
                        ];
                    }, $participants);

                    $eventData[] = [
                        'id_event' => $event->id_event,
                        'event_name' => $event->event_name,
                        'start_date' => $event->start_date,
                        'end_date' => $event->end_date,
                        'status' => $event->status,
                        'id_customer' => $event->id_customer,
                        'participants' => $formattedParticipants
                    ];
                }

                Yii::$app->response->statusCode = 200;
                return \yii\helpers\Json::encode($eventData);
            } else {
                Yii::$app->response->statusCode = 401;
                return \yii\helpers\Json::encode(['error' => 'Unauthorized']);
            }
        } else {
            Yii::$app->response->statusCode = 401;
            return \yii\helpers\Json::encode(['error' => 'Unauthorized']);
        }
    }

    public function actionAddEvent()
    {   
        $headers = Yii::$app->request->headers;
        if ($headers->has('Authorization')) {
            $authorizationHeader = $headers->get('Authorization');
            $token = str_replace('Bearer ', '', $authorizationHeader);
            $user = Customer::find()->where(['authKey' => $token])->one();
            
            if($user){
                
                $data = Yii::$app->request->post();
                $rawBody = Yii::$app->request->rawBody;
                $data = json_decode($rawBody, true);


                $newEvent = new Events();
                // $newExpense->id_type = $id_event;
                $newEvent->id_customer = $user->id;
                // $newExpense->id_category = Yii::$app->request->post('category'); 
                // $newEvent->description = Yii::$app->request->post('description'); 
                $newEvent->event_name =  Yii::$app->request->post('name'); 
                $newEvent->status = Yii::$app->request->post('status'); 

                $start_date = DateTime::createFromFormat('Y-m-d', Yii::$app->request->post('start_date'));
                if ($start_date) {
                    $newEvent->start_date = $start_date->format('Y-m-d');
                } else {
                    Yii::$app->response->statusCode = 400;
                    return \yii\helpers\Json::encode(['error' => 'Invalid date format']);
                }

                $end_date = DateTime::createFromFormat('Y-m-d', Yii::$app->request->post('end_date'));
                if ($end_date) {
                    $newEvent->end_date = $end_date->format('Y-m-d');
                } else {
                    Yii::$app->response->statusCode = 400;
                    return \yii\helpers\Json::encode(['error' => 'Invalid date format']);
                }

                // Handle the uploaded image
                // $imageFile = UploadedFile::getInstanceByName('image');
                // if ($imageFile) {
                //     $uploadPath = Yii::getAlias('@webroot') . '/expenses/';
                //     $imageName = time() . '_' . $imageFile->baseName . '.' . $imageFile->extension;
                //     $imageFile->saveAs($uploadPath . $imageName);


                //     $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
                //     $fileExtensions = ['pdf'];
                //     $imageFileExtension = strtolower($imageFile->extension);

                //     if (in_array($imageFileExtension, $imageExtensions)) {

                //         $image = Image::make($uploadPath . $imageName);
                //         $image->encode('jpg', 70); 
                //         $compressedImageName = 'wplus_' . $imageName;
                //         $image->save($uploadPath . $compressedImageName);
                //         $newExpense->image = $compressedImageName;

                //     } else if (in_array($imageFileExtension, $fileExtensions)) {
                //         $newExpense->image = $imageName;
                //     } else {
                //         Yii::$app->response->statusCode = 500;
                //         return \yii\helpers\Json::encode(['error' => 'Unsupported image type. Please upload a JPG, PNG, GIF, BMP, or WebP file.']);
                //     }

                // }

                if ($newEvent->save()) {

                    if (!empty($participants)) {
                        foreach ($participants as $participantId) {
                            $eventParticipant = new EventParticipants();
                            $eventParticipant->id_event = $newEvent->id; // Assuming $newEvent is the event object
                            $eventParticipant->id_customer = $participantId; // Assuming participant ID is the user ID
                            if (!$eventParticipant->save()) {
                                // Handle error if saving fails
                                Yii::error('Failed to add participant ID: ' . $participantId);
                            }
                        }
                    }

                    Yii::$app->response->statusCode = 201; // Created status code
                    return \yii\helpers\Json::encode($newEvent);
                } else {
                    Yii::$app->response->statusCode = 422; // Unprocessable Entity status code
                    return \yii\helpers\Json::encode($newEvent->getErrors());
                }
            } else {
                Yii::$app->response->statusCode = 401;
                return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
            }
        } else {
            Yii::$app->response->statusCode = 401;
            return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
        }
        
    }

    public function actionUpdateEvent()
    {   

        $headers = Yii::$app->request->headers;
        if ($headers->has('Authorization')) {
            $authorizationHeader = $headers->get('Authorization');
            $token = str_replace('Bearer ', '', $authorizationHeader);
            $user = Customer::find()->where(['authKey' => $token])->one();
            if($user){
                $id = Yii::$app->request->post('id');
                // $data = Yii::$app->request->post();

                
                $newEvent = Events::findOne($id);
                $newEvent->id_customer = $user->id;
                $newEvent->event_name =  Yii::$app->request->post('name'); 
                $newEvent->status = Yii::$app->request->post('status'); 

                $start_date = DateTime::createFromFormat('Y-m-d', Yii::$app->request->post('start_date'));
                if ($start_date) {
                    $newEvent->start_date = $start_date->format('Y-m-d');
                } else {
                    Yii::$app->response->statusCode = 400;
                    return \yii\helpers\Json::encode(['error' => 'Invalid date format']);
                }

                $end_date = DateTime::createFromFormat('Y-m-d', Yii::$app->request->post('end_date'));
                if ($end_date) {
                    $newEvent->end_date = $end_date->format('Y-m-d');
                } else {
                    Yii::$app->response->statusCode = 400;
                    return \yii\helpers\Json::encode(['error' => 'Invalid date format']);
                }
                
                $participantsJson = Yii::$app->request->post('participants', '[]');  // Default to empty JSON array if not set
                $newParticipants = json_decode($participantsJson, true);  

                $existingParticipants = EventParticipants::find()
                    ->select('id_customer')
                    ->where(['id_event' => $id])
                    ->column(); 

                // Find participants to remove (present in the existing but not in the new list)
                $participantsToRemove = array_diff($existingParticipants, $newParticipants);

                // Find participants to add (present in the new list but not in the existing list)
                $participantsToAdd = array_diff($newParticipants, $existingParticipants);

                // Remove participants no longer assigned to the event
                if (!empty($participantsToRemove)) {
                    EventParticipants::deleteAll([
                        'id_event' => $id,
                        'id_customer' => $participantsToRemove,
                    ]);
                }

                // Add new participants to the event
                if (!empty($participantsToAdd)) {
                    foreach ($participantsToAdd as $participantId) {
                        $eventParticipant = new EventParticipants();
                        $eventParticipant->id_event = $id;
                        $eventParticipant->id_customer = $participantId;
                        if (!$eventParticipant->save()) {
                            Yii::error('Failed to add participant ID: ' . $participantId);
                        }
                    }
                }

                // // Handle the uploaded image
                // $imageFile = UploadedFile::getInstanceByName('image');
                // if ($imageFile) {
                //     $uploadPath = Yii::getAlias('@webroot') . '/expenses/';
                //     $imageName = time() . '_' . $imageFile->baseName . '.' . $imageFile->extension;
                //     $imageFile->saveAs($uploadPath . $imageName);

                //     $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
                //     $fileExtensions = ['pdf'];
                //     $imageFileExtension = strtolower($imageFile->extension);

                //     if (in_array($imageFileExtension, $imageExtensions)) {

                //         $image = Image::make($uploadPath . $imageName);
                //         $image->encode('jpg', 70); 
                //         $compressedImageName = 'wplus_' . $imageName;
                //         $image->save($uploadPath . $compressedImageName);
                //         $newExpense->image = $compressedImageName;

                //     } else if (in_array($imageFileExtension, $fileExtensions)) {
                //         $newExpense->image = $imageName;
                //     } else {
                //         Yii::$app->response->statusCode = 500;
                //         return \yii\helpers\Json::encode(['error' => 'Unsupported image type. Please upload a JPG, PNG, GIF, BMP, or WebP file.']);
                //     }
                // }

                if ($newEvent->save()) {
                    Yii::$app->response->statusCode = 201; // Created status code
                    return \yii\helpers\Json::encode($newEvent);
                } else {
                    Yii::$app->response->statusCode = 422; // Unprocessable Entity status code
                    return \yii\helpers\Json::encode($newEvent->getErrors());
                }
            } else {
                Yii::$app->response->statusCode = 401;
                return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
            }
        } else {
            Yii::$app->response->statusCode = 401;
            return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
        }

        
    }

    public function actionDeleteEvent()
    {   

        $headers = Yii::$app->request->headers;
        if ($headers->has('Authorization')) {
            $authorizationHeader = $headers->get('Authorization');
            $token = str_replace('Bearer ', '', $authorizationHeader);
            $user = Customer::find()->where(['authKey' => $token])->one();
            if($user){
                $rawBody = Yii::$app->request->rawBody;
                $data = json_decode($rawBody, true);

                $event = Events::findOne($data['id']);

                if ($event) {                 
            
                    if ($event->delete()) {
                        Yii::$app->response->statusCode = 204; // No Content status code
                        return \yii\helpers\Json::encode(['message' => 'Event deleted successfully']);
                    } else {
                        Yii::$app->response->statusCode = 500; // Internal Server Error status code
                        return \yii\helpers\Json::encode(['error' => 'Failed to delete event']);
                    }
                } else {
                    Yii::$app->response->statusCode = 404; // Not Found status code
                    return \yii\helpers\Json::encode(['error' => 'Event not found']);
                }
            } else {
                Yii::$app->response->statusCode = 401;
                return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
            }
        } else {
            Yii::$app->response->statusCode = 401;
            return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
        }

        
    }

    public function actionEventDetails(){
        $headers = Yii::$app->request->headers;
        if ($headers->has('Authorization')) {
            $authorizationHeader = $headers->get('Authorization');
            $token = str_replace('Bearer ', '', $authorizationHeader);
            $user = Customer::find()->where(['authKey' => $token])->one();
            if($user){
                $id = Yii::$app->request->post('id');
                $query = (new Query())
                ->select('*')
                ->from('bt_events')
                // ->where(['bt_events.id_customer' => $user->id])
                ->where(['bt_events.id_event' => $id]);
                
                $command = $query->createCommand();
                $eventData = $command->queryOne();


                $participantsQuery = (new Query())
                ->select('id_customer')
                ->from('bt_event_participants')
                ->where(['id_event' => $id]);

                $participantsCommand = $participantsQuery->createCommand();
                $participants = $participantsCommand->queryColumn(); // Retrieves an array of participant IDs

                $participants = array_map('intval', $participants);

                $response = [
                    'data' => $eventData,
                    'participants' => $participants,
                ];
                Yii::$app->response->statusCode = 200;
                return \yii\helpers\Json::encode($response);
            } else {
                Yii::$app->response->statusCode = 401;
                return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
            }
        } else {
            Yii::$app->response->statusCode = 401;
            return \yii\helpers\Json::encode(['error' => 'UnAuthorized']);
        }
    }


    public function beforeAction($action)
    {
        if (in_array($action->id, ['events','add-event','update-event','event-details','send-report','statistics', 'category-list', 'get', 'update','delete','delete-event', 'get-list', 'add', 'suggestion'])) {
            $this->enableCsrfValidation = false;
        }
        return parent::beforeAction($action);
    }

}