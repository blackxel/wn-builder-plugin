<?php namespace RainLab\Builder\Classes;

use Symfony\Component\Yaml\Dumper as YamlDumper;
use ApplicationException;
use ValidationException;
use Validator;
use Yaml;
use File;
use Lang;

/**
 * Base class for models that store data in YAML files.
 *
 * @package rainlab\builder
 * @author Alexey Bobkov, Samuel Georges
 */
abstract class YamlModel
{
    /**
     * @var boolean This property is used by the system internally.
     */
    public $exists = false;

    /**
     * @var string Section in the YAML file to save the data into.
     * If empty, the model contents uses the entire file.
     */
    protected $yamlSection;

    protected $originalFilePath;

    protected $updatedData = [];

    protected $originalFileData = [];

    protected $validationRules = [];

    protected $validationMessages = [];

    protected static $fillable = [];

    public function save()
    {
        $data = $this->modelToYamlArray();
        $this->validate();

        if ($this->yamlSection) {
            $fileData = $this->originalFileData;
            $fileData[$this->yamlSection] = $data;
            $data = $fileData;
        }

        $dumper = new YamlDumper();
        $yamlData = $dumper->dump($data, 20, 0, false, true);

        $filePath = File::symbolizePath($this->getFilePath());

        if (File::isFile($filePath) && $this->isNewModel()) {
            throw new ApplicationException(Lang::get('rainlab.builder::lang.common.error_file_exists', ['path'=>$filePath]));
        }

        $fileDirectory = dirname($filePath);
        if (!File::isDirectory($fileDirectory)) {
            if (!File::makeDirectory($fileDirectory, 0777, true, true)) {
                throw new ApplicationException(Lang::get('rainlab.builder::lang.common.error_make_dir', ['name'=>$fileDirectory]));
            }
        }

        if (@File::put($filePath, $yamlData) === false) {
            throw new ApplicationException(Lang::get('rainlab.builder::lang.yaml.save_error', ['name'=>$filePath]));
        }

        @File::chmod($filePath);

        if ($this->isNewModel()) {
            $this->afterCreate();
        }

        if ($this->yamlSection) {
            $this->originalFileData = $data;
        }

        $this->originalFilePath = $filePath;
    }

    public function load($filePath)
    {
        $filePath = File::symbolizePath($filePath);

        $data = Yaml::parse(File::get($filePath));
        $this->originalFilePath = $filePath;

        if ($this->yamlSection) {
            $this->originalFileData = $data;
            if (!is_array($this->originalFileData)) {
                $this->originalFileData = [];
            }

            if (array_key_exists($this->yamlSection, $data)) {
                $data = $data[$this->yamlSection];
            }
            else {
                $data = [];
            }
        }

        $this->yamlArrayToModel($data);
    }

    public function fill(array $attributes)
    {
        $this->updatedData = [];

        foreach ($attributes as $key => $value) {
            if (!in_array($key, static::$fillable)) {
                continue;
            }

            $methodName = 'set'.ucfirst($key);
            if (method_exists($this, $methodName)) {
                $this->$methodName($value);
            }
            else {
                $this->$key = $value;
            }

            $this->updatedData[$key] = $value;
        }
    }

    public function initDefaults()
    {
    }

    protected function isNewModel()
    {
        return !strlen($this->originalFilePath);
    }

    protected function validate()
    {
        $existingData = [];
        foreach (static::$fillable as $field) {
            $existingData[$field] = $this->$field;
        }

        $validation = Validator::make(
            array_merge($existingData, $this->updatedData),
            $this->validationRules,
            $this->validationMessages
        );

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        if (!$this->isNewModel()) {
            $this->validateBeforeCreate();
        }
    }

    protected function validateBeforeCreate()
    {
    }

    protected function afterCreate()
    {
    }

    protected function getArrayKeySafe($array, $key, $default = null)
    {
        return array_key_exists($key, $array) ? $array[$key] : $default;
    }

    /**
     * Converts the model's data to an array before it's saved to a YAML file.
     * @return array
     */
    abstract protected function modelToYamlArray();

    /**
     * Load the model's data from an array.
     * @param array $array An array to load the model fields from.
     */
    abstract protected function yamlArrayToModel($array);

    /**
     * Returns a file path to save the model to.
     * @return string Returns a path.
     */
    abstract protected function getFilePath();
}