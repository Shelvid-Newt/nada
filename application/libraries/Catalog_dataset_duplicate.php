<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Duplicate a catalog study.
 *
 * The copy is built from the same document the JSON export produces (metadata, tags and,
 * optionally, the data description: data files, variables and variable groups) and is created
 * through Catalog_dataset_import, so it follows exactly the same path as a JSON import.
 *
 * What is copied:    metadata, tags, data access type, remote data URL, data classification,
 *                    collection (owner repository) and, optionally, data files + variables.
 * What is NOT copied: DOI and alternate IDNOs (must stay unique), publication status (the copy
 *                    is always a draft), thumbnail, files/resources on disk, citations, notes.
 */
class Catalog_dataset_duplicate
{
    /**
     * Types that can be duplicated, with the metadata path of their IDNO.
     * Time series and tables are excluded: their values live outside the catalog database.
     */
    private $idno_paths = array(
        'survey'     => 'study_desc/title_statement/idno',
        'document'   => 'document_description/title_statement/idno',
        'script'     => 'project_desc/title_statement/idno',
        'image'      => 'image_description/idno',
        'video'      => 'video_description/idno',
        'geospatial' => 'description/idno',
    );

    /**
     * Candidate metadata paths of the title, per type (first one present in the source is used).
     */
    private $title_paths = array(
        'survey'     => array('study_desc/title_statement/title'),
        'document'   => array('document_description/title_statement/title'),
        'script'     => array('project_desc/title_statement/title'),
        'image'      => array('image_description/dcmi/title', 'image_description/iptc/photoVideoMetadataIPTC/headline'),
        'video'      => array('video_description/title'),
        'geospatial' => array('description/identificationInfo/citation/title'),
    );

    /**
     * Metadata sections listing identifiers (DOI, alternate IDNOs) per type.
     */
    private $identifier_paths = array(
        'survey' => 'study_desc/title_statement/identifiers',
        'script' => 'project_desc/title_statement/identifiers',
    );

    /** surveys.idno is VARCHAR(200) */
    const MAX_IDNO_LENGTH = 200;

    private $ci;

    public function __construct()
    {
        $this->ci =& get_instance();
        $this->ci->load->helper('array');
        $this->ci->load->model('Dataset_model');
        $this->ci->load->model('Data_file_model');
        $this->ci->load->model('Variable_model');
        $this->ci->load->model('Variable_group_model');
        $this->ci->load->library('Dataset_manager');
        $this->ci->load->library('Catalog_dataset_import');
    }


    /**
     * @param string $type
     * @return bool
     */
    public function is_supported_type($type)
    {
        return isset($this->idno_paths[$type]);
    }


    /**
     * What the "Duplicate" dialog needs to know about a study.
     *
     * @param int         $sid
     * @param string|null $title_suffix text appended to the suggested title, e.g. "(copy)"
     * @return array
     * @throws Exception
     */
    public function get_info($sid, $title_suffix = null)
    {
        $row = $this->get_source_row($sid);

        $data_files = $this->ci->Data_file_model->get_all_by_survey($sid);
        $counts     = array(
            'data_files'      => is_array($data_files) ? count($data_files) : 0,
            'variables'       => 0,
            'variable_groups' => 0,
        );

        if ($row['type'] === 'survey') {
            $counts['variables']       = (int) $this->ci->Variable_model->get_variables_count($sid);
            $counts['variable_groups'] = count((array) $this->ci->Variable_group_model->select_all($sid));
        }

        return array(
            'id'                => (int) $row['id'],
            'type'              => $row['type'],
            'supported'         => $this->is_supported_type($row['type']),
            'idno'              => $row['idno'],
            'title'             => $row['title'],
            'suggested_idno'    => $this->suggest_idno($row['idno']),
            'suggested_title'   => $this->suggest_title($row['title'], $title_suffix),
            'data_description'  => array_merge(
                $counts,
                array('available' => $counts['data_files'] > 0 || $counts['variables'] > 0)
            ),
        );
    }


    /**
     * First free IDNO of the form {idno}-copy, {idno}-copy-2, {idno}-copy-3, ...
     *
     * @param string $idno
     * @return string
     */
    public function suggest_idno($idno)
    {
        $base = $this->truncate_idno((string) $idno, strlen('-copy-999'));

        for ($i = 1; $i <= 999; $i++) {
            $candidate = $base . ($i === 1 ? '-copy' : '-copy-' . $i);
            if (! $this->ci->dataset_manager->find_by_idno($candidate)) {
                return $candidate;
            }
        }

        return $base . '-copy-' . substr(md5(uniqid('', true)), 0, 6);
    }


    /**
     * @param string      $title
     * @param string|null $suffix
     * @return string
     */
    public function suggest_title($title, $suffix = null)
    {
        $title  = trim((string) $title);
        $suffix = trim((string) $suffix);

        return $suffix === '' ? $title : $title . ' ' . $suffix;
    }


    /**
     * Create a copy of a study.
     *
     * @param int   $sid    source study id
     * @param array $params new_idno (string, optional), new_title (string, optional),
     *                      include_data_description (bool), user_id (int)
     * @return array{sid:int,idno:string,type:string,dataset:array,data_description:array}
     * @throws Exception IDNO_ALREADY_EXISTS | DUPLICATE_NOT_SUPPORTED | IDNO_TOO_LONG | ...
     */
    public function duplicate($sid, array $params = array())
    {
        $row = $this->get_source_row($sid);
        $type = $row['type'];

        if (! $this->is_supported_type($type)) {
            throw new Exception('DUPLICATE_NOT_SUPPORTED: ' . $type);
        }

        $new_idno = isset($params['new_idno']) ? trim((string) $params['new_idno']) : '';
        if ($new_idno === '') {
            $new_idno = $this->suggest_idno($row['idno']);
        }

        if (strlen($new_idno) > self::MAX_IDNO_LENGTH) {
            throw new Exception('IDNO_TOO_LONG');
        }

        if ($this->ci->dataset_manager->find_by_idno($new_idno)) {
            throw new Exception('IDNO_ALREADY_EXISTS');
        }

        $new_title = isset($params['new_title']) ? trim((string) $params['new_title']) : '';
        $include   = ! empty($params['include_data_description']) && $type === 'survey';

        $options = $this->build_options($sid, $type, $include);
        $this->prepare_options($options, $type, $new_idno, $new_title);

        $repositoryid = ! empty($row['repositoryid']) ? $row['repositoryid'] : 'central';

        $import_params = array(
            'repositoryid' => $repositoryid,
            'overwrite'    => false,
            'published'    => 0, // a copy is always a draft until someone reviews it
            'user_id'      => isset($params['user_id']) ? (int) $params['user_id'] : null,
        );

        if (! empty($row['formid'])) {
            $import_params['formid'] = (int) $row['formid'];
        }

        if (! empty($row['remote_data_url'])) {
            $import_params['link_da'] = $row['remote_data_url'];
        }

        $created = $this->ci->catalog_dataset_import->create_from_options($options, $import_params);

        if (! empty($row['data_class_id'])) {
            $this->ci->dataset_manager->update_options(
                $created['sid'],
                array('data_class_id' => (int) $row['data_class_id'])
            );
        }

        $created['data_description'] = array(
            'included'        => $include,
            'data_files'      => isset($options['data_files']) ? count($options['data_files']) : 0,
            'variables'       => isset($options['variables']) ? count($options['variables']) : 0,
            'variable_groups' => isset($options['variable_groups']) ? count($options['variable_groups']) : 0,
        );

        return $created;
    }


    /**
     * Study document as the JSON export would produce it.
     *
     * @param int    $sid
     * @param string $type
     * @param bool   $include_data_description add data files, variables and variable groups
     * @return array
     */
    public function build_options($sid, $type, $include_data_description = false)
    {
        $options         = (array) $this->ci->Dataset_model->get_metadata($sid);
        $options['type'] = $type;

        if (! $include_data_description || $type !== 'survey') {
            return $options;
        }

        // Rows come straight from the database: unset columns are NULL, which the schemas reject
        // (`type: string`), so only the fields that hold a value are passed on.
        $data_files = array();
        foreach ((array) $this->ci->Data_file_model->get_all_by_survey($sid) as $file) {
            unset($file['id'], $file['sid']);
            $data_files[] = array_filter($file, function ($value) {
                return $value !== null;
            });
        }

        $variables = array();
        foreach ($this->ci->Variable_model->chunk_reader_generator($sid) as $variable) {
            $variables[] = $variable['metadata'];
        }

        $variable_groups = array();
        foreach ((array) $this->ci->Variable_group_model->select_all($sid) as $group) {
            unset($group['id'], $group['sid']);
            $variable_groups[] = array_filter($group, function ($value) {
                return $value !== null && $value !== '';
            });
        }

        $options['data_files']      = $data_files;
        $options['variables']       = $variables;
        $options['variable_groups'] = $variable_groups;

        return $options;
    }


    /**
     * Turn a source study document into the document of its copy: new IDNO and title, and no
     * identifier that has to stay unique (DOI, alternate IDNOs).
     *
     * @param array  $options   source document (modified in place)
     * @param string $type
     * @param string $new_idno
     * @param string $new_title empty to keep the source title
     * @return void
     */
    public function prepare_options(array &$options, $type, $new_idno, $new_title = '')
    {
        if (! $this->is_supported_type($type)) {
            throw new Exception('DUPLICATE_NOT_SUPPORTED: ' . $type);
        }

        set_array_nested_value($options, $this->idno_paths[$type], $new_idno);

        if ($new_title !== '') {
            foreach ($this->title_paths[$type] as $path) {
                if (get_array_nested_value($options, $path) !== null) {
                    set_array_nested_value($options, $path, $new_title);
                    break;
                }
            }
        }

        // a DOI identifies one study only
        $this->unset_path($options, 'datacite/doi');
        unset($options['doi']);

        if (isset($this->identifier_paths[$type])) {
            $identifiers = get_array_nested_value($options, $this->identifier_paths[$type]);

            if (is_array($identifiers)) {
                $kept = array();
                foreach ($identifiers as $identifier) {
                    $identifier_type = is_array($identifier) && isset($identifier['type'])
                        ? strtolower((string) $identifier['type'])
                        : '';
                    if ($identifier_type !== 'doi' && $identifier_type !== 'idno') {
                        $kept[] = $identifier;
                    }
                }

                if (count($kept) > 0) {
                    set_array_nested_value($options, $this->identifier_paths[$type], $kept);
                } else {
                    $this->unset_path($options, $this->identifier_paths[$type]);
                }
            }
        }
    }


    /**
     * Remove a nested value without creating the missing parents on the way.
     *
     * @param array  $array
     * @param string $path  slash-separated
     * @return void
     */
    private function unset_path(array &$array, $path)
    {
        $keys = explode('/', $path);
        $leaf = array_pop($keys);
        $ref  = &$array;

        foreach ($keys as $key) {
            if (! is_array($ref) || ! array_key_exists($key, $ref)) {
                return;
            }
            $ref = &$ref[$key];
        }

        if (is_array($ref)) {
            unset($ref[$leaf]);
        }
    }


    /**
     * @param int $sid
     * @return array
     * @throws Exception
     */
    private function get_source_row($sid)
    {
        $row = $this->ci->Dataset_model->get_row($sid);

        if (! $row) {
            throw new Exception('IDNO-NOT-FOUND');
        }

        return $row;
    }


    /**
     * Shorten an IDNO so that the suffix still fits in the column.
     *
     * @param string $idno
     * @param int    $reserve characters to leave free
     * @return string
     */
    private function truncate_idno($idno, $reserve)
    {
        return strlen($idno) > self::MAX_IDNO_LENGTH - $reserve
            ? substr($idno, 0, self::MAX_IDNO_LENGTH - $reserve)
            : $idno;
    }
}
