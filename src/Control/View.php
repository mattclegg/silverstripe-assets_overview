<?php

namespace Sunnysideup\AssetsOverview\Control;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\View\SSViewer;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Requirements;
use Sunnysideup\AssetsOverview\Api\CompareImages;
use Sunnysideup\AssetsOverview\Files\AllFilesInfo;
use Sunnysideup\AssetsOverview\Files\OneFileInfo;
use Sunnysideup\AssetsOverview\Traits\FilesystemRelatedTraits;

class View extends ContentController
{
    use FilesystemRelatedTraits;

    private const ALL_FILES_INFO_CLASS = AllFilesInfo::class;

    private const ONE_FILE_INFO_CLASS = OneFileInfo::class;

    private const SORTERS = [
        'byfolder' => [
            'Title' => 'Folder',
            'Sort' => 'PathFromAssetsFolder',
            'Group' => 'PathFromAssetsFolderFolderOnly',
        ],
        'byfilename' => [
            'Title' => 'Filename',
            'Sort' => 'FileName',
            'Group' => 'FirstLetter',
        ],
        'bydbtitle' => [
            'Title' => 'Database Title',
            'Sort' => 'DBTitle',
            'Group' => 'FirstLetterDBTitle',
        ],
        'byfilesize' => [
            'Title' => 'Filesize',
            'Sort' => 'FileSize',
            'Group' => 'HumanFileSizeRounded',
        ],
        'bylastedited' => [
            'Title' => 'Last Edited',
            'Sort' => 'LastEditedTS',
            'Group' => 'LastEdited',
        ],
        'byextension' => [
            'Title' => 'Extension',
            'Sort' => 'ExtensionAsLower',
            'Group' => 'ExtensionAsLower',
        ],
        'byisimage' => [
            'Title' => 'Image vs Other Files',
            'Sort' => 'IsImage',
            'Group' => 'HumanIsImage',
        ],
        'byclassname' => [
            'Title' => 'Class Name',
            'Sort' => 'ClassName',
            'Group' => 'ClassName',
        ],
        'bydimensions' => [
            'Title' => 'Dimensions (small to big)',
            'Sort' => 'Pixels',
            'Group' => 'HumanImageDimensions',
        ],
        'byratio' => [
            'Title' => 'Ratio',
            'Sort' => 'Ratio',
            'Group' => 'Ratio',
        ],
    ];


    private const FILTERS = [
        'byextensionerror' => [
            'Title' => 'Case error in file type',
            'Field' => 'HasIrregularExtension',
            'Values' => [0, false],
        ],
        'bymissingfromlive' => [
            'Title' => 'Unpublished',
            'Field' => 'IsInDatabaseLive',
            'Values' => [0, false],
        ],
        'bymissingfromstaging' => [
            'Title' => 'Missing from drafts',
            'Field' => 'IsInDatabaseStaging',
            'Values' => [0, false],
        ],
        'bydatabaseerror' => [
            'Title' => 'Case error in file name',
            'Field' => 'ErrorInFilenameCase',
            'Values' => [1, true],
        ],
        'by3to4error' => [
            'Title' => 'Migration to SS4 errors',
            'Field' => 'ErrorInSs3Ss4Comparison',
            'Values' => [1, true],
        ],
        'byfoldererror' => [
            'Title' => 'Folder Error',
            'Field' => 'ErrorParentID',
            'Values' => [1, true],
        ],
        'byfilesystemstatus' => [
            'Title' => 'Missing from file system',
            'Field' => 'IsInFileSystem',
            'Values' => [0, false],
        ],
    ];

    private const DISPLAYERS = [
        'thumbs' => 'Thumbnails',
        'rawlist' => 'File List',
        'rawlistfull' => 'Raw Data',
    ];

    /**
     * @var ArrayList|null
     */
    protected $filesAsArrayList = null;

    /**
     * @var ArrayList|null
     */
    protected $filesAsSortedArrayList = null;

    /**
     * @var string
     */
    protected $title = '';

    /**
     * @var int
     */
    protected $totalFileCountRaw = 0;

    /**
     * @var int
     */
    protected $totalFileCountFiltered = 0;

    /**
     * @var int
     */
    protected $totalFileSizeFiltered = 0;

    /**
     * @var int
     */
    protected $limit = 1000;

    /**
     * @var int
     */
    protected $startLimit = 0;

    /**
     * @var int
     */
    protected $endLimit = 0;

    /**
     * @var int
     */
    protected $pageNumber = 1;

    /**
     * @var string
     */
    protected $sorter = 'byfolder';

    /**
     * @var string
     */
    protected $filter = '';

    /**
     * @var string
     */
    protected $displayer = 'thumbs';

    /**
     * @var array
     */
    protected $allowedExtensions = [];

    /**
     * Defines methods that can be called directly
     * @var array
     */
    private static $allowed_actions = [
        'index' => 'ADMIN',
        'json' => 'ADMIN',
        'jsonfull' => 'ADMIN',
    ];

    public function Link($action = null)
    {
        $str = Director::absoluteURL(DIRECTORY_SEPARATOR . 'assets-overview' . DIRECTORY_SEPARATOR);
        if ($action) {
            $str = $action . DIRECTORY_SEPARATOR;
        }
        return $str;
    }

    public function getTitle(): string
    {
        $array = array_filter([$this->getSortStatement(), $this->getFilterStatement(), $this->getPageStatement()]);
        return 'Showing all files'.
            implode(', ', $array);
    }

    public function getSortStatement() : string
    {
        return 'sorted by ' . self::SORTERS[$this->sorter]['Title'] ?? 'ERROR IN SORTER';
    }

    public function getFilterStatement() : string
    {
        return $this->filter ? 'filtered for: ' . self::FILTERS[$this->filter]['Title'] :  '';
    }

    public function getPageStatement() : string
    {
        return $this->getNumberOfPages() > 1 ?
            ', from '.($this->startLimit * $this->limit) .
            ' to ' . ($this->endLimit * $this->limit).' out of '.$this->getTotalFileCountFiltered()
            :
            '';
    }

    public function getDisplayer(): string
    {
        return $this->displayer;
    }

    public function getfilesAsArrayList(): ArrayList
    {
        return $this->filesAsArrayList;
    }

    public function getfilesAsSortedArrayList(): ArrayList
    {
        return $this->filesAsSortedArrayList;
    }

    public function getTotalFileCountRaw(): string
    {
        return (string) number_format($this->totalFileCountRaw);
    }

    public function getTotalFileCountFiltered(): string
    {
        return (string) number_format($this->totalFileCountFiltered);
    }

    public function getTotalFileSize(): string
    {
        return (string) $this->humanFileSize($this->totalFileSizeFiltered);
    }

    public function init()
    {
        parent::init();
        if (! Permission::check('ADMIN')) {
            return Security::permissionFailure($this);
        }
        Requirements::clear();
        SSViewer::config()->update('theme_enabled', false);
        if ($filter = $this->request->getVar('filter')) {
            $this->filter = $filter;
        }
        if ($sorter = $this->request->getVar('sorter')) {
            $this->sorter = $sorter;
        }
        if ($displayer = $this->request->getVar('displayer')) {
            $this->displayer = displayer;
        }

        if ($extensions = $this->request->getVar('extensions')) {
            if (! is_array($extensions)) {
                $extensions = [$extensions];
            }
            $this->allowedExtensions = $extensions;
            //make sure all are valid!
            $this->allowedExtensions = array_filter($this->allowedExtensions);
        }
        if ($limit = $this->request->getVar('limit')) {
            $this->limit = $limit;
        }
        if ($pageNumber = $this->request->getVar('page')) {
            $this->pageNumber = $pageNumber;
        }
        $this->startLimit = $this->limit * ($this->pageNumber - 1);
        $this->endLimit = $this->limit * $this->pageNumber;
    }

    public function index($request)
    {
        $this->setFilesAsSortedArrayList();
        if ($this->displayer === 'rawlistfull') {
            $this->addMapToItems();
        }

        return $this->renderWith('AssetsOverview');
    }

    public function json($request)
    {
        return $this->sendJSON($this->getRawData());
    }

    public function jsonfull($request)
    {
        $array = [];
        $this->setFilesAsArrayList();
        foreach ($this->filesAsArrayList->toArray() as $item) {
            $array[] = $item->toMap();
        }
        return $this->sendJSON($array);
    }

    public function addMapToItems()
    {
        $this->isThumbList = false;
        foreach ($this->filesAsSortedArrayList as $group) {
            foreach ($group->Items as $item) {
                $map = $item->toMap();
                $item->FullFields = ArrayList::create();
                foreach ($map as $key => $value) {
                    $item->FullFields->push(ArrayData(['Key' => $key, 'Value' => $value]));
                }
            }
        }
    }

    ##############################################
    # FORM
    ##############################################
    public function Form()
    {
        return $this->getForm();
    }

    protected function sendJSON($data)
    {
        $this->response->addHeader('Content-Type', 'application/json');
        $fileData = json_encode($data, JSON_PRETTY_PRINT);
        if ($this->request->getVar('download')) {
            return HTTPRequest::send_file($fileData, 'files.json', 'text/json');
        }
        return $fileData;
    }

    protected function setfilesAsSortedArrayList()
    {
        if ($this->filesAsSortedArrayList === null) {
            $sorterData = self::SORTERS[$this->sorter];
            $sortField = $sorterData['Sort'];
            $headerField = $sorterData['Group'];
            //done only if not already done ...
            $this->setFilesAsArrayList();
            $this->filesAsSortedArrayList = ArrayList::create();
            $this->filesAsArrayList = $this->filesAsArrayList->Sort($sortField);

            $innerArray = ArrayList::create();
            $prevHeader = 'nothing here....';
            $newHeader = '';
            foreach ($this->filesAsArrayList as $file) {
                $newHeader = $file->{$headerField};
                if ($newHeader !== $prevHeader) {
                    $this->addTofilesAsSortedArrayList(
                        $prevHeader, //correct! important ...
                        $innerArray
                    );
                    $prevHeader = $newHeader;
                    unset($innerArray);
                    $innerArray = ArrayList::create();
                }
                $innerArray->push($file);
            }

            //last one!
            $this->addTofilesAsSortedArrayList(
                $newHeader,
                $innerArray
            );
        }

        return $this->filesAsSortedArrayList;
    }

    protected function addTofilesAsSortedArrayList(string $header, ArrayList $arrayList)
    {
        if ($arrayList->count()) {
            $count = $this->filesAsSortedArrayList->count();
            $this->filesAsSortedArrayList->push(
                ArrayData::create(
                    [
                        'Number' => $count,
                        'SubTitle' => $header,
                        'Items' => $arrayList,
                    ]
                )
            );
        }
    }

    protected function setFilesAsArrayList(): ArrayList
    {
        if ($this->filesAsArrayList === null) {
            $rawArray = $this->getRawData();
            //prepare loop
            $this->totalFileCountRaw = AllFilesInfo::getTotalFilesCount();
            $this->filesAsArrayList = ArrayList::create();
            $count = 0;
            $filterFree = true;
            if (isset(self::FILTERS[$this->filter])) {
                $filterFree = false;
                $filterField = self::FILTERS['Field'];
                $filterValues = self::FILTERS['Values'];
            }
            foreach ($rawArray as $absoluteLocation => $fileExists) {
                if ($this->isPathWithAllowedExtension($absoluteLocation)) {
                    if ($count >= $this->startLimit && $count < $this->endLimit) {
                        $intel = $this->getDataAboutOneFile($absoluteLocation, $fileExists);
                        if ($filterFree || in_array($intel[$filterField], $filterValues, 1)) {
                            $count++;
                            $this->totalFileCountFiltered++;
                            $this->totalFileSizeFiltered += $intel['FileSize'];
                            $this->filesAsArrayList->push(
                                ArrayData::create($intel)
                            );
                        }
                    } elseif ($count >= $this->endLimit) {
                        break;
                    }
                }
            }
        }

        return $this->filesAsArrayList;
    }

    protected function getRawData(): array
    {
        //get data
        $class = self::ALL_FILES_INFO_CLASS;
        $obj = new $class($this->getAssetsBaseFolder());

        return $obj->toArray();
    }

    protected function getDataAboutOneFile(string $absoluteLocation, ?bool $fileExists): array
    {
        $class = self::ONE_FILE_INFO_CLASS;
        $obj = new $class($absoluteLocation, $fileExists);

        return $obj->toArray();
    }

    /**
     * @param  string $path - does not have to be full path.
     *
     * @return bool
     */
    protected function isPathWithAllowedExtension(string $path): bool
    {
        $count = count($this->allowedExtensions);
        if ($count === 0) {
            return true;
        }
        $extension = strtolower($this->getExtension($path));
        if (in_array($extension, $this->allowedExtensions, true)) {
            return true;
        }
        return false;
    }

    protected function createFormField(string $name, string $title, $value, ?array $list = [])
    {
        $listCount = count($list);
        if ($listCount === 0) {
            $type = HiddenField::class;
        } elseif ($name === 'extensions') {
            $type = CheckboxSetField::class;
        } elseif ($listCount < 20) {
            $type = OptionsetField::class;
        } else {
            $type = DropdownField::class;
        }

        $field = $type::create($name, $title)
            ->setValue($value);
        if ($listCount) {
            $field->setSource($list);
        }
        $field->setAttribute('onchange', 'this.form.submit()');

        return $field;
    }
    protected function getForm(): Form
    {
        $fieldList = FieldList::create(
            [
                $this->createFormField('sorter', 'Sort By', $this->sorter, $this->getSorterList()),
                $this->createFormField('filter', 'Filter for', $this->filter, $this->getFilterList()),
                $this->createFormField('displayer', 'Displayed by', $this->filter, $this->getDisplayerList()),
                $this->createFormField('extensions', 'Extensions', $this->allowedExtensions, $this->getExtensionList()),
                $this->createFormField('limit', 'Items Per Page', $this->limit, $this->getLimitList()),
                $this->createFormField('page', 'Page Number', $this->pageNumber, $this->getPageNumberList()),
                // TextField::create('compare', 'Compare With')->setDescription('add a link to a comparison file - e.g. http://oldsite.com/assets-overview/test.json'),
            ]
        );
        $actionList = FieldList::create(
            [
                FormAction::create('index', 'Update File List'),
            ]
        );

        $form = Form::create($this, 'index', $fieldList, $actionList);
        $form->setFormMethod('GET', true);
        $form->disableSecurityToken();

        return $form;
    }

    protected function getSorterList(): array
    {
        $array = [];
        foreach (self::SORTERS as $key => $data) {
            $array[$key] = $data['Title'];
        }

        return $array;
    }

    protected function getFilterList(): array
    {
        $array = [];
        foreach (self::FILTERS as $key => $data) {
            $array[$key] = $data['Title'];
        }

        return $array;
    }

    protected function getDisplayerList(): array
    {
        return self::DISPLAYERS;
    }

    protected function getExtensionList(): array
    {
        return AllFilesInfo::getAvailableExtensions();
    }

    protected function getPageNumberList(): array
    {
        $list = range(1, $this->getNumberOfPages());
        $list = array_combine($list, $list);
        $list[(string) $this->pageNumber] = (string) $this->pageNumber;
        if (count($list) < 2) {
            return [];
        }
        return $list;
    }

    protected function getNumberOfPages(): Int
    {
        return ceil($this->totalFileCountFiltered / $this->limit);
    }

    protected function getLimitList(): array
    {
        $step = 250;
        $array = [];
        for ($i = $step; ($i - $step) < $this->totalFileCountFiltered; $i += $step) {
            if ($i > $this->limit && ! isset($array[$this->limit])) {
                $array[$this->limit] = $this->limit;
            }
            $array[$i] = $i;
        }
        if ($i > $this->limit && ! isset($array[$this->limit])) {
            $array[$this->limit] = $this->limit;
        }
        return $array;
    }
}
