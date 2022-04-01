<?php
/**
 * author     : zzbajie <zzbajie@gmail.com>
 * createTime : 2020-02-18 21:53:56
 * description:
 */

namespace zzbajie\aliyunOSS;

use Yii;
use yii\base\Action;
use yii\base\DynamicModel;
use yii\base\InvalidConfigException;
use yii\base\UserException;
use yii\helpers\FileHelper;
use yii\web\Response;
use yii\web\UploadedFile;

class FileUploadAction extends Action
{
    /**
     * 上传文件的 file 参数名
     * @var string
     */
    public $fileParam = 'filename';

    /**
     * @link https://www.yiiframework.com/doc/guide/2.0/en/input-validation#ad-hoc-validation
     * @var array
     */
    public $validationRules = [
        [
            'file',
            'file',
            'extensions' => ['png', 'jpeg', 'jpg', 'gif', 'webp', 'bmp'],
            'checkExtensionByMimeType' => false,
            'mimeTypes' => 'image/*',
            'maxSize' => 5 * 1024 * 1024
        ]
    ];

    /**
     * 文件名生成的方式，默认用 md5
     * @var callable
     */
    public $fileSaveNameCallback;

    /**
     * 文件保存的方法，默认用 UploadedFile::saveAs()
     * @var callable
     */
    public $saveFileCallback;

    /**
     * 返回结果回调函数
     * @var callable
     */
    public $returnCallback;

    /**
     * @var bool
     */
    public $normalizePath = 'auto';

    /**
     * 文件保存路径
     * @var string
     */
    public $savePath = '@webroot/uploads';

    /**
     * 文件显示的路径
     * @var string
     */
    public $webPath = '@web/uploads';

    /**
     * @var string
     */
    public $uploadSaveErrorMassage = '上传的文件保存失败';

    /**
     * 是否保留本地文件
     * @var bool
     */
    public $keepLocalFile = false;

    /**
     * @inheritdoc
     */
    public function init()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->request->enableCsrfValidation = false;
        if ($this->normalizePath === 'auto') {
            $this->normalizePath = strpos($this->savePath, '..') !== false;
        }
        parent::init();
    }

    /**
     * @inheritdoc
     *
     * @return array
     * @throws InvalidConfigException
     */
    public function run()
    {
        $uploadedFiles = UploadedFile::getInstancesByName($this->fileParam);
        $resultData = [];
        foreach ($uploadedFiles as $key => $uploadedFile) {
            $validationModel = DynamicModel::validateData(['file' => $uploadedFile], $this->validationRules);
            if ($validationModel->hasErrors()) {
                $errorMassage = $validationModel->getFirstError('file');
                break;
            }
            try {
                $filename = $this->getFileName($uploadedFile);
                if (!$this->saveFile($uploadedFile, $filename)) {
                    throw new UserException($this->uploadSaveErrorMassage);
                }
                $this->uploadOSS($filename);
                if (!$this->keepLocalFile) {
                    $this->deleteLocalFile($filename);
                }
                $resultData[$key] = $this->getFullFilename($filename, $this->webPath);
                continue;
            } catch (UserException $e) {
                Yii::error($e, 'upload error');
                $errorMassage = $e->getMessage();
                break;
            }
        }

        if (isset($errorMassage)) {
            // 失败了要删除之前的
            foreach ($resultData as $resultDatum) {
                $this->deleteFile($resultDatum);
            }
            return $this->parseResult(500, $errorMassage);
        }
        return $this->parseResult(0, '', $resultData);
    }


    /**
     * @param $uploadedFile UploadedFile
     * @param string $filename
     * @return bool
     * @throws \yii\base\Exception
     */
    protected function saveFile(UploadedFile $uploadedFile, string $filename)
    {
        $filename = $this->getFullFilename($filename, $this->savePath);
        if ($this->saveFileCallback && is_callable($this->saveFileCallback)) {
            return call_user_func($this->saveFileCallback, $filename, $uploadedFile, $this);
        }
        FileHelper::createDirectory(dirname($filename));
        return $uploadedFile->saveAs($filename);
    }


    /**
     * @param $uploadedFile UploadedFile
     * @return string
     * @throws \Exception
     */
    private function getFileName($uploadedFile)
    {
        if ($this->fileSaveNameCallback && is_callable($this->fileSaveNameCallback)) {
            $filename = call_user_func($this->fileSaveNameCallback, $uploadedFile, $this);
        } else {
            $filename = md5(microtime() . random_int(10000, 99999));
        }
        if (strpos($filename, '.') === false) {
            $filename .= '.' . $uploadedFile->getExtension();
        }
        return $filename;
    }

    /**
     * @param $filename
     * @param $path
     * @return bool|string
     */
    protected function getFullFilename($filename, $path)
    {
        $filename = Yii::getAlias(rtrim($path, '/') . '/' . $filename);
        if ($this->normalizePath) {
            return FileHelper::normalizePath($filename);
        }
        return $filename;
    }

    /**
     * Parse result
     * @param int $code
     * @param string $message
     * @param array $data
     * @return array
     */
    protected function parseResult(int $code, $message = '', $data = [])
    {
        if ($this->returnCallback && is_callable($this->returnCallback)) {
            return call_user_func($this->returnCallback, $code, $message, $data);
        }
        return ['code' => $code, 'massage' => $message, 'data' => $data];
    }

    /**
     * @param string $fileWebName
     * @throws InvalidConfigException
     */
    protected function deleteFile(string $fileWebName)
    {
        $fileWebNames = explode('/', $fileWebName);
        $this->deleteLocalFile(end($fileWebNames));
        $oss = \Yii::$app->get('oss');
        $oss->delete(ltrim($fileWebName, '/'));
    }

    /**
     * @param string $filename
     */
    protected function deleteLocalFile(string $filename)
    {
        $fileAbsoluteName = $this->getFullFilename($filename, $this->savePath);
        @unlink($fileAbsoluteName);
    }

    /**
     * @param string $filename
     * @throws InvalidConfigException
     */
    public function uploadOSS(string $filename)
    {
        $fileAbsoluteName = $this->getFullFilename($filename, $this->savePath);
        $fileWebName = $this->getFullFilename($filename, $this->webPath);
        $oss = \Yii::$app->get('oss');
        $oss->upload(ltrim($fileWebName, '/'), $fileAbsoluteName);
    }
}