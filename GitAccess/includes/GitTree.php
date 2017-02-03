<?php
/**
 * GitAccess MediaWiki Extension---Access wiki content with Git.
 * Copyright (C) 2017  Matthew Trescott
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 * @file
 */

/**
 * Class interfacing between Git tree objects and MediaWiki's pages.
 */
class GitTree extends AbstractGitObject
{
    public $tree_data;
    
    const T_NORMAL_FILE = 100644;
    const T_EXEC_FILE = 100755;
    const T_SYMLINK = 120000;
    const T_TREE = 40000;
    
    
    public function addToRepo()
    {
        $this->repo->trees[$this->getHash()] = &$this;
    }
    
    public function export()
    {
        $tree = '';
        
        foreach ($this->tree_data as $entry)
        {
            $tree = $tree . $entry['type'] . ' ' . $entry['name'] . "\0" . $entry['object']->getHash(true);
        }
        
        $length = strlen($tree);
        $tree = 'tree ' . $length . "\0" . $tree;
        
        return $tree;
    }
    
    /**
     * Organizes files into subtrees
     * Finds files with a forward slash in the name and builds a
     * directory structure.
     * 
     * @param int $ns_id The namespace id (e.g. NS_MAIN, NS_TALK, etc.) that this
     * tree represents or is in. Used to determine whether to attempt to process
     * the subpages.
     */
    public function processSubpages($ns_id)
    {
        if (!MWNamespace::hasSubpages($ns_id)) { return; }
        
        $subpages = array();
        foreach ($this->tree_data as $key => $entry)
        {
            if ($entry['type'] != self::T_NORMAL_FILE) { continue; }
            
            // Find the part before the first slash
            if(preg_match('~^(.[^\/]*)\/(.+)$~', $entry['name'], $matches) === 0)
            {
                continue;
            }
            
            /* Make sure there's an entry in the array of subpage directories
             * that matches the containing part.
             */
            if (!$subpages[$matches[1]]) { $subpages[$matches[1]] = array(); }
            $new_entry = array(
                'name' => $matches[2],
                'type' => self::T_NORMAL_FILE,
                'object' => &$entry['object'] // Blob doesn't change
            );
            array_push($subpages[$matches[1]], $new_entry); // Add the entry to the list files under the page
            
            unset($this->tree_data[$key]); // Remove the original entry from the main tree
        }
        
        foreach ($subpages as $name => $entry)
        {
            $subpage_tree = new self();
            $subpage_tree->tree_data = $entry;
            $subpage_tree->processSubpages();
            //Illegal characters and capitalization passes...?
            $subpage_tree->addToRepo();
            $subpage_tree_entry = array(
                'name' => $name,
                'type' => self::T_TREE,
                'object' => &$subpage_tree
            );
            array_push($this->tree_data, $subpage_tree_entry);
        }
    }
    
    public static function parse($tree)
    {
        sscanf($tree, "tree %d\0", $length);
        $raw_entries = substr(
            $tree,
            strpos($tree, "\0") + 1,
            $length
        );
        $raw_bytes = str_split($raw_entries);
        
        $tree_data = array();
        
        for ($i = 0; isset($raw_entries[$i]); ++$i)
        {
            /* Either the null is marking the beginning of the hash,
             * or it is part of the hash itself. However, the latter
             * case should not appear, since $i will be pushed past
             * the hash automatically upon encountering any NUL char.
             */
            if ($raw_bytes[$i] === "\0")
            {
                if (!isset($beginning_of_entry))
                {
                    /* "Rewind" to beginning of string to get type and name.
                     * This relies on a subtle difference between isset() and
                     * empty().
                     */
                    $beginning_of_entry = 0;
                }
                
                sscanf(
                    substr($raw_entries, $beginning_of_entry, $i - $beginning_of_entry),
                    "%d %s[^\t\n]",
                    $type_id,
                    $filename
                );
                
                $hash_bin = substr($raw_entries, $i + 1, 20);
                
                $file_entry = array('type' => $type_id, 'name' => $filename, 'hash_bin' => $hash_bin);
                array_push($tree_data, $file_entry);
                
                $i = $i + 21; // Push $i past the hash
                $beginning_of_entry = $i;
            }
        }
        // Fetch the object in object form
        foreach ($tree_data as $key => $entry)
        {
            switch $entry['type'] {
                case self::T_NORMAL_FILE:
                case self::T_EXEC_FILE:
                    $tree_data[$key]['object'] = $this->repo->&fetchBlob(bin2hex($entry['hash_bin']));
                    break;
                case self::T_TREE:
                    $tree_data[$key]['object'] = $this->repo->&fetchTree(bin2hex($entry['hash_bin']));
                    break;
                default:
                    // Panic/convulsions...? Who knows?
            }
        }
        
        return $tree_data;
    }
    
    public static function newFromData($data)
    {
        $instance = new self();
        $instance->tree_data = self::parse($data);
    }
    
    /**
     * Generate a new root GitTree
     * Creates a tree from the GitAccess_root namespace, then appends other namespaces
     * to it as directories.
     * 
     * @param int $rev_id The revision ID to build the tree at
     * @param int $log_id The log ID used for reference when building the tree.
     * @return GitTree The root tree
     */
    public static function newRoot($rev_id, $log_id)
    {
        $instance = self::newFromNamespace($rev_id, $log_id, NS_GITACCESS_ROOT);
        
        $namespaces = array_flip(MWNamespace::getCanonicalNamespaces());
        $namespaces = array_fill_keys(array_keys($namespaces), 1); // All namespaces included
        $namespaces = array_merge($namespaces, $GLOBALS['wgGitAccessNSIncluded']); // Un-include some namespaces
        /* Un-include dynamically generated namespaces.
         * Note that the Media folder is used to store files with GitAccess.
         * The File folder stores the description pages.
         */
        $namespaces['Media'] = false;
        $namespaces['Special'] = false;

        
        foreach ($namespaces as $name => $isIncluded)
        {
            if (!$isIncluded) { continue; }
            if ($name == 'File')
            {
                $media_tree = new self();
                $media_tree->tree_data = array();
            }
            
            $ns_tree = self::newFromNamespace(
                $rev_id,
                $log_id,
                MWNamespace::getCanonicalIndex(strtolower($name)),
                (($name == 'File') ? $media_tree : null)
            );
            
            // Empty trees should not be included
            if ($ns_tree->tree_data)
            {
                $ns_tree->addToRepo();
                array_push(
                    $instance->tree_data,
                    array(
                        'type' => self::T_TREE,
                        'name' => $name ?: '(Main)',
                        'object' => &$instance
                    )
                );
            }
        }
        
        // Empty trees should not be included
        if ($media_tree->tree_data)
        {
            $media_tree->addToRepo();
            array_push(
                $instance->tree_data,
                array(
                    'type' => self::T_TREE,
                    'name' => 'Media',
                    'object' => &$media_tree
                )
            );
        }
        
        return $instance;
    }
    
    /**
     * Generates a new GitTree from a single namespace
     * 
     * @param int $rev_id The revision ID to build the tree at
     * @param int $log_id The log ID used for reference when building the tree.
     * @param int $ns_id The namespace ID to build the tree from
     * @param GitTree &$media_tree (optional) The GitTree used to store files. Populated when
     * $ns_id is NS_FILE.
     * @return GitTree The generated GitTree
     */
    public static function newFromNamespace($rev_id, $log_id, $ns_id, &$media_tree = null)
    {
        $dbw = wfGetDB(DB_MASTER);
        
        /* {{{ SQL stuff */
        $sql = $dbw->selectSQLText(
            array('page', 'revision'),
            array(
                'is_archive' => '\'false\'',
                'page.page_id',
                'page.page_namespace',
                'rev_id' => 'MAX(revision.rev_id)'
            ),
            array(
                'rev_id <= ' . $rev_id,
                'page_namespace' => $ns_id
            ),
            __METHOD__,
            array(
                'GROUP BY' => array('\'false\'', 'page_id', 'page_namespace')
            ),
            array(
                'revision' => array('INNER JOIN', 'page_id = rev_page')
            )
        );
        $sql .= ' UNION ';
        $sql .= $dbw->selectSQLText(
            'archive',
            array(
                'is_archive' => '\'true\'',
                'page_id' => 'ar_page_id',
                'page_namespace' => 'ar_namespace',
                'rev_id' => 'MAX(ar_rev_id)'
            ),
            array(
                'ar_rev_id <= ' . $rev_id,
                'ar_namespace' => $ns_id
            ),
            __METHOD__,
            array(
                'GROUP BY' => array('\'true\'', 'ar_page_id', 'ar_namespace')
            )
        );
        $result = $dbw->query($sql);
        /* }}}  End SQL stuff*/
        
        $mimeTypesRepo = new Dflydev\ApacheMimeTypes\FlatRepository(
            "$IP/extensions/GitAccess/vendor/dflydev-apache-mimetypes/mime.types"
        );
        
        $instance = new self();
        $instance->tree_data = array();
        do
        {
            $row = $result->fetchRow();
            if ($row)
            {
                // Fetch Revision
                if ($row['is_archive'] === 'true')
                {
                    $ar_row = $dbw->selectRow(
                        'archive',
                        Revision::selectArchiveFields(),
                        array('ar_rev_id' => $row['rev_id'])
                    );
                    $revision = Revision::newFromArchiveRow($ar_row);
                }
                else
                {
                    $revision = Revision::newFromId($row['rev_id'], Revision::READ_LATEST);
                }
                
                $titleValue = self::getTitleAtRevision($revision, $log_id);
                
                if (self::pageExisted($titleValue, $log_id))
                {
                    $blob = GitBlob::newFromRaw($revision->getContent(Revision::RAW)->serialize());
                    $blob->addToRepo();
                    array_push(
                        $instance->tree_data,
                            array(
                            'type' => self::T_NORMAL_FILE,
                            'name' => $titleValue->getDBKey() . self::determineFileExt($titleValue, $revision),
                            'object' => &$blob
                        )
                    );
                    
                    if ($ns_id == NS_FILE) { self::fetchFile($media_tree, $revision, $titleValue); }
                }
            }
        }
        while ($row);
        
        // Filter passes
        if ($ns_id != NS_FILE && $ns_id != NS_GITACCESS_ROOT) { $instance->processSubpages($ns_id); }
        
        return $instance;
    }
    
    /**
     * Fetches a file and adds it to the tree representing the Media  namespace
     * 
     * @param GitTree &$media_tree The GitTree used to add the file to
     * @param Revision $revision The revision of the page in the File namespace
     * @param TitleValue $title The time-dependent title of the revision (see GitTree::getTitleAtRevision())
     */
    public static function fetchFile(GitTree &$media_tree, Revision $revision, TitleValue $title)
    {
        $file = RepoGroup::singleton()->getLocalRepo()->newFile(
            $revision->getTitle(),
            $revision->getTimestamp()
        );
        if (!$file) { return; }
        /* newFile() always returns an OldLocalFile instance,
         * so OldLocalFile::getRel() always returns a path containing
         * 'archive'. However if the file is actually the current
         * version, getArchiveName() will return NULL.
         */
        $fileIsOld = $file->getArchiveName() ? true : false;
        if ($fileIsOld)
        {
            $path = $IP . '/images/' . $file->getRel() . $file->getArchiveName();
        }
        else
        {
            preg_match('~^archive\\/(.*)$~', $file->getRel(), $matches);
            $path = $IP . '/images/' . $matches[1] . $file->getName();
        }
        $blob = GitBlob::newFromRaw(file_get_contents($path));
        $blob->addToRepo();
        
        array_push(
            $media_tree->tree_data,
            array(
                'type' => ($file->getMediaType == MEDIATYPE_EXECUTABLE)
                            ? self::T_EXEC_FILE
                            : self::T_NORMAL_FILE,
                'name' => $title->getDBKey(),
                'object' => &$blob
            )
        );
    }
    
    /**
     * Gets the actual name a page had at a point in history.
     * Revision::getTitle() always returns the current title of the page,
     * which causes big problems since it would change the hashes of Git trees.
     * This utility method searches the logging table to be sure the page wasn't moved
     * in the past.
     * 
     * @param Revision $revision The revision to fetch the title for
     * @param int $log_id (optional) The log_id to use in searching the logging table, for better accuracy
     * @return TitleValue The title of the page at the given revision
     */
    public static function getTitleAtRevision(Revision $revision, $log_id = null)
    {
        $dbw = wfGetDB(DB_MASTER);
        $conds = array(
            'log_page' => $revision->getPage(),
            'log_action' => 'move',
            'log_timestamp <= ' . $revision->getTimestamp(),
        );
        if ($log_id) { array_push($conds, 'log_id <= ' . $log_id); }
        
        $result = $dbw->selectRow(
            'logging',
            array(
                'log_id' => 'MAX(log_id)'
            ),
            $conds
        );
        if ($result->log_id)
        {
            $titleText = DatabaseLogEntry::newFromRow(
                $dbw->selectRow(
                    'logging',
                    '*',
                    'log_id=' . $result->log_id
                )
            )->getParameters()['4::target'];
            
            return MediaWikiServices::getInstance()->getTitleParser()->parseTitle($titleText, NS_MAIN);
        }
        else
        {
            return new TitleValue($revision->getTitle()->getNamespace(), $revision->getTitle()->getDBKey());
        }
    }
    
    /**
     * Decides the file extension a file should have
     * This is usually based on the mimetype stored in the revision table,
     * but some pages like user CSS and JavaScript pages have their content
     * format fields based on the page name. It wouldn't make sense to have
     * User/MTres19/my_css.css.css, which is what you'd get without checking
     * for existing file extensions. Of course, this should be skipped in the
     * File namespace.
     * 
     * @param TitleValue $title The title of page to find the file extension for
     * @param Revision $rev The revision to get the mimetype from if needed
     * @return string The file extension, including the dot, or an empty sting if
     * the page name already contains an extension.
     */
    public static function determineFileExt(TitleValue $title, Revision $rev)
    {
        $mimeTypesRepo = new Dflydev\ApacheMimeTypes\FlatRepository(
            "$IP/extensions/GitAccess/vendor/dflydev-apache-mimetypes/mime.types"
        );
        
        preg_match('~^.*\.(.[^\.]*)$~', $title->getDBKey(), $matches);
        $extFromTitle = !empty($matches[1]) ? $matches[1] : null;
        
        if ($title->getNamespace() != NS_FILE && $extFromTitle && $mimeTypesRepo->findType($extFromTitle))
        {
            return '';
        }
        else
        {
            return '.' . $mimeTypesRepo->findExtensions($rev->getContentFormat())[0];
        }
    }
    
    /**
     * Figures out whether the page was deleted at the time
     * (Whether it should appear in the tree)
     * 
     * @param TitleValue $title The title of the page to check
     * @param int $log_id The most recent log ID for the commit referencing this tree
     */
    
    public static function pageExisted(TitleValue $title, $log_id)
    {
        $dbw = wfGetDB(DB_MASTER);
        $del_log_id = $dbw->selectField(
            'logging',
            'MAX(log_id)',
            array(
                'log_id <= ' . $log_id,
                'log_type' => 'delete',
                'log_namespace' => $title->getNamespace(),
                'log_title' => $title->getDBKey()
            )
        );
        
        $action = $dbw->selectField(
            'logging',
            'log_action',
            array('log_id' => $del_log_id)
        );
        
        if ($action = 'delete')
        {
            return false;
        }
        elseif ($action = 'restore')
        {
            return true;
        }
    }
}
