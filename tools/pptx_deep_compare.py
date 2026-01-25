#!/usr/bin/env python3
"""
PPTX Deep Comparison Tool - OPC/OOXML Semantic Analysis
Compares a corrupt PPTX with its PowerPoint-repaired version to identify root causes.

Usage:
    python tools/pptx_deep_compare.py /tmp/merge.pptx /tmp/merge_repaired.pptx
"""

import sys
import os
import zipfile
import hashlib
import json
import xml.etree.ElementTree as ET
from collections import defaultdict
from pathlib import Path
import re
from typing import Dict, List, Set, Tuple, Optional, Any

# OPC/OOXML Namespaces
NS = {
    'ct': 'http://schemas.openxmlformats.org/package/2006/content-types',
    'rel': 'http://schemas.openxmlformats.org/package/2006/relationships',
    'p': 'http://schemas.openxmlformats.org/presentationml/2006/main',
    'r': 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
    'a': 'http://schemas.openxmlformats.org/drawingml/2006/main',
}

REL_TYPES = {
    'slide': 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide',
    'slideMaster': 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster',
    'slideLayout': 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout',
    'theme': 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme',
    'notesMaster': 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesMaster',
    'notesSlide': 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide',
    'image': 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image',
}

class PPTXAnalyzer:
    """Analyzes a single PPTX file."""
    
    def __init__(self, path: str, label: str = ""):
        self.path = path
        self.label = label or os.path.basename(path)
        self.zip = zipfile.ZipFile(path, 'r')
        self.files: Dict[str, bytes] = {}
        self.hashes: Dict[str, str] = {}
        self.content_types: Dict[str, str] = {}
        self.default_types: Dict[str, str] = {}
        self.relations: Dict[str, List[Dict]] = {}  # part -> [relations]
        self.issues: List[Dict] = []
        
    def analyze(self):
        """Run full analysis."""
        self._load_files()
        self._parse_content_types()
        self._parse_all_rels()
        self._validate_references()
        return self
        
    def _load_files(self):
        """Load all files and compute hashes."""
        for name in self.zip.namelist():
            content = self.zip.read(name)
            self.files[name] = content
            self.hashes[name] = hashlib.sha256(content).hexdigest()[:16]
            
    def _parse_content_types(self):
        """Parse [Content_Types].xml."""
        if '[Content_Types].xml' not in self.files:
            self.issues.append({'type': 'CRITICAL', 'msg': 'Missing [Content_Types].xml'})
            return
            
        root = ET.fromstring(self.files['[Content_Types].xml'])
        for default in root.findall('ct:Default', NS):
            ext = default.get('Extension', '')
            ctype = default.get('ContentType', '')
            self.default_types[ext] = ctype
            
        for override in root.findall('ct:Override', NS):
            part = override.get('PartName', '').lstrip('/')
            ctype = override.get('ContentType', '')
            self.content_types[part] = ctype
            
    def _parse_all_rels(self):
        """Parse all .rels files."""
        for name in self.files:
            if name.endswith('.rels'):
                self._parse_rels(name)
                
    def _parse_rels(self, rels_path: str):
        """Parse a single .rels file."""
        try:
            root = ET.fromstring(self.files[rels_path])
        except ET.ParseError as e:
            self.issues.append({'type': 'ERROR', 'msg': f'Invalid XML in {rels_path}: {e}'})
            return
            
        # Determine the source part
        source_part = self._rels_to_part(rels_path)
        rels = []
        
        for rel in root.findall('rel:Relationship', NS):
            rid = rel.get('Id', '')
            rtype = rel.get('Type', '')
            target = rel.get('Target', '')
            mode = rel.get('TargetMode', 'Internal')
            
            rels.append({
                'id': rid,
                'type': rtype,
                'target': target,
                'mode': mode,
                'resolved': self._resolve_target(source_part, target) if mode != 'External' else target
            })
            
        self.relations[source_part] = rels
        
    def _rels_to_part(self, rels_path: str) -> str:
        """Convert .rels path to the source part path."""
        # e.g., ppt/_rels/presentation.xml.rels -> ppt/presentation.xml
        if rels_path == '_rels/.rels':
            return ''  # Root relations
        parts = rels_path.split('/')
        rels_idx = parts.index('_rels')
        base = '/'.join(parts[:rels_idx])
        filename = parts[-1].replace('.rels', '')
        return f"{base}/{filename}" if base else filename
        
    def _resolve_target(self, source: str, target: str) -> str:
        """Resolve relative target path."""
        if target.startswith('/'):
            return target.lstrip('/')
        if not source:
            return target
        base = '/'.join(source.split('/')[:-1])
        if not base:
            return target
        # Handle ../ in paths
        parts = (base + '/' + target).split('/')
        resolved = []
        for p in parts:
            if p == '..':
                if resolved:
                    resolved.pop()
            elif p and p != '.':
                resolved.append(p)
        return '/'.join(resolved)
        
    def _validate_references(self):
        """Check for broken references."""
        all_parts = set(self.files.keys())
        
        for source, rels in self.relations.items():
            # Check for duplicate rId in same .rels
            rid_counts = defaultdict(int)
            for rel in rels:
                rid_counts[rel['id']] += 1
            for rid, count in rid_counts.items():
                if count > 1:
                    self.issues.append({
                        'type': 'DUPLICATE_RID',
                        'msg': f"Duplicate rId '{rid}' in relations for: {source}",
                        'source': source,
                        'rid': rid,
                        'count': count
                    })
            
            for rel in rels:
                if rel['mode'] == 'External':
                    continue
                target = rel['resolved']
                if target and target not in all_parts:
                    self.issues.append({
                        'type': 'BROKEN_REF',
                        'msg': f"Broken reference: {source} -> {target} (rId={rel['id']})",
                        'source': source,
                        'target': target,
                        'rid': rel['id']
                    })
                    
        # Check Content_Types coverage
        for part in all_parts:
            if part.startswith('_rels/') or part.endswith('.rels'):
                continue
            if part == '[Content_Types].xml':
                continue
            ext = part.split('.')[-1] if '.' in part else ''
            if part not in self.content_types and ext not in self.default_types:
                self.issues.append({
                    'type': 'MISSING_CONTENT_TYPE',
                    'msg': f"No content type for: {part}",
                    'part': part
                })
        
        # Validate slideLayout -> slideMaster chain
        self._validate_layout_master_chain()

        # Validate unique IDs in presentation.xml
        self._validate_presentation_ids()

        # Validate notesSlides
        self._validate_noteslides()

        # Validate app.xml properties
        self._validate_app_properties()

        # Validate comments
        self._validate_comments()
    
    def _validate_layout_master_chain(self):
        """Verify each slideLayout references a valid slideMaster."""
        for name in self.files:
            if not re.match(r'ppt/slideLayouts/slideLayout\d+\.xml$', name):
                continue
            layout_rels_path = name.replace('ppt/slideLayouts/', 'ppt/slideLayouts/_rels/') + '.rels'
            if layout_rels_path not in self.files:
                self.issues.append({
                    'type': 'MISSING_LAYOUT_RELS',
                    'msg': f"SlideLayout missing .rels file: {name}",
                    'part': name
                })
                continue
            
            # Check for slideMaster relation
            rels = self.relations.get(name, [])
            has_master = any(REL_TYPES['slideMaster'] in r['type'] for r in rels)
            if not has_master:
                self.issues.append({
                    'type': 'LAYOUT_NO_MASTER',
                    'msg': f"SlideLayout has no slideMaster relation: {name}",
                    'part': name
                })
    
    def _validate_presentation_ids(self):
        """Check for duplicate slideId/slideMasterId in presentation.xml."""
        if 'ppt/presentation.xml' not in self.files:
            return
        try:
            root = ET.fromstring(self.files['ppt/presentation.xml'])
        except ET.ParseError:
            return

        # Check slideId uniqueness
        slide_ids = []
        for sld in root.findall('.//p:sldIdLst/p:sldId', NS):
            sid = sld.get('id')
            if sid:
                slide_ids.append(sid)
        duplicates = [s for s in slide_ids if slide_ids.count(s) > 1]
        if duplicates:
            self.issues.append({
                'type': 'DUPLICATE_SLIDE_ID',
                'msg': f"Duplicate slideId values: {set(duplicates)}",
                'ids': list(set(duplicates))
            })

        # Check slideMasterId uniqueness
        master_ids = []
        for sm in root.findall('.//p:sldMasterIdLst/p:sldMasterId', NS):
            mid = sm.get('id')
            if mid:
                master_ids.append(mid)
        duplicates = [m for m in master_ids if master_ids.count(m) > 1]
        if duplicates:
            self.issues.append({
                'type': 'DUPLICATE_MASTER_ID',
                'msg': f"Duplicate slideMasterId values: {set(duplicates)}",
                'ids': list(set(duplicates))
            })

        # Validate presentation.xml.rels - check for invalid relation types
        self._validate_presentation_rels()

        # Validate sections
        self._validate_sections(root)

        # Validate notesMaster references
        self._validate_notes_master(root)

    def _validate_presentation_rels(self):
        """Validate presentation.xml.rels for incorrect relation types."""
        pres_rels = self.relations.get('ppt/presentation.xml', [])

        # Valid relation types for presentation.xml.rels
        VALID_PRES_REL_TYPES = {
            'slide',
            'slideMaster',
            'notesMaster',
            'handoutMaster',
            'presProps',
            'viewProps',
            'tableStyles',
            'theme',
            'tags',
            'commentAuthors',
            'customXml',
            'font',
            'revisionInfo',
        }

        # Relations that should NEVER be directly in presentation.xml.rels
        INVALID_PRES_REL_TYPES = {
            'image': 'Images should be in slide/layout/master .rels, not presentation.xml.rels',
            'slideLayout': 'SlideLayouts should be in slideMaster .rels, not presentation.xml.rels',
            'notesSlide': 'NotesSlides should be in slide .rels, not presentation.xml.rels',
            'audio': 'Audio should be in slide .rels, not presentation.xml.rels',
            'video': 'Video should be in slide .rels, not presentation.xml.rels',
            'chart': 'Charts should be in slide .rels, not presentation.xml.rels',
            'oleObject': 'OLE objects should be in slide .rels, not presentation.xml.rels',
        }

        invalid_rels = []
        for rel in pres_rels:
            rel_type_name = rel['type'].split('/')[-1]  # Get just the type name

            if rel_type_name in INVALID_PRES_REL_TYPES:
                invalid_rels.append({
                    'rid': rel['id'],
                    'type': rel_type_name,
                    'target': rel['target'],
                    'reason': INVALID_PRES_REL_TYPES[rel_type_name]
                })

        if invalid_rels:
            self.issues.append({
                'type': 'INVALID_PRESENTATION_RELS',
                'msg': f"presentation.xml.rels contains {len(invalid_rels)} invalid relation types",
                'invalid_relations': invalid_rels
            })

            # Group by type for clearer reporting
            by_type = {}
            for r in invalid_rels:
                if r['type'] not in by_type:
                    by_type[r['type']] = []
                by_type[r['type']].append(r)

            for rel_type, rels in by_type.items():
                self.issues.append({
                    'type': f'PRES_RELS_INVALID_{rel_type.upper()}',
                    'msg': f"{len(rels)} {rel_type} relations incorrectly in presentation.xml.rels: {[r['rid'] for r in rels[:5]]}{'...' if len(rels) > 5 else ''}",
                    'count': len(rels),
                    'rids': [r['rid'] for r in rels],
                    'targets': [r['target'] for r in rels]
                })

    def _validate_sections(self, pres_root):
        """Validate section references in presentation.xml."""
        sections = []
        for sect in pres_root.findall('.//p:sectionLst/p:section', NS):
            name = sect.get('name', '')
            section_id = sect.get('id', '')
            slide_id_refs = []
            for sldIdRef in sect.findall('.//p:sldIdLst/p:sldId', NS):
                slide_id_refs.append({
                    'id': sldIdRef.get('id'),
                    'rid': sldIdRef.get('{%s}id' % NS['r'])
                })
            sections.append({
                'name': name,
                'id': section_id,
                'slideIds': slide_id_refs
            })

        # Check if section slideIds reference valid slides
        all_slide_ids = set()
        for sld in pres_root.findall('.//p:sldIdLst/p:sldId', NS):
            sid = sld.get('id')
            if sid:
                all_slide_ids.add(sid)

        for section in sections:
            for slide_ref in section['slideIds']:
                if slide_ref['id'] and slide_ref['id'] not in all_slide_ids:
                    self.issues.append({
                        'type': 'SECTION_INVALID_SLIDE_REF',
                        'msg': f"Section '{section['name']}' references non-existent slideId: {slide_ref['id']}",
                        'section': section['name'],
                        'slideId': slide_ref['id']
                    })

    def _validate_notes_master(self, pres_root):
        """Validate notesMaster reference."""
        notes_master = pres_root.find('.//p:notesMasterIdLst/p:notesMasterId', NS)
        if notes_master is not None:
            rid = notes_master.get('{%s}id' % NS['r'])
            if rid:
                # Check if the relation exists
                rels = self.relations.get('ppt/presentation.xml', [])
                has_rel = any(r['id'] == rid for r in rels)
                if not has_rel:
                    self.issues.append({
                        'type': 'MISSING_NOTESMASTER_REL',
                        'msg': f"notesMaster references rId {rid} but relation not found",
                        'rid': rid
                    })

    def _validate_noteslides(self):
        """Validate all notesSlides for bidirectional references."""
        for name in self.files:
            if not re.match(r'ppt/notesSlides/notesSlide\d+\.xml$', name):
                continue

            try:
                root = ET.fromstring(self.files[name])
            except ET.ParseError:
                continue

            # Check for slide reference
            clr_map_ovr = root.find('.//p:clrMapOvr', NS)
            if clr_map_ovr is not None:
                slide_ref = clr_map_ovr.get('{%s}id' % NS['r'])
                if slide_ref:
                    # Check if relation exists
                    rels = self.relations.get(name, [])
                    has_slide_rel = any(r['id'] == slide_ref and 'slide' in r['type'] for r in rels)
                    if not has_slide_rel:
                        self.issues.append({
                            'type': 'NOTESLIDE_MISSING_SLIDE_REF',
                            'msg': f"NoteSlide {name} references slide rId {slide_ref} but relation not found",
                            'noteslide': name,
                            'rid': slide_ref
                        })

            # Check for notesMaster reference
            rels = self.relations.get(name, [])
            has_master = any('notesMaster' in r['type'] for r in rels)
            if not has_master:
                self.issues.append({
                    'type': 'NOTESLIDE_NO_MASTER',
                    'msg': f"NoteSlide {name} has no notesMaster relation",
                    'noteslide': name
                })

    def _validate_app_properties(self):
        """Validate docProps/app.xml counts."""
        if 'docProps/app.xml' not in self.files:
            return

        try:
            root = ET.fromstring(self.files['docProps/app.xml'])
        except ET.ParseError:
            return

        # Count slides and notes
        slides_elem = root.find('.//{http://schemas.openxmlformats.org/officeDocument/2006/extended-properties}Slides')
        notes_elem = root.find('.//{http://schemas.openxmlformats.org/officeDocument/2006/extended-properties}Notes')

        declared_slides = int(slides_elem.text) if slides_elem is not None and slides_elem.text else 0
        declared_notes = int(notes_elem.text) if notes_elem is not None and notes_elem.text else 0

        # Count actual slides and notes
        actual_slides = len([f for f in self.files if re.match(r'ppt/slides/slide\d+\.xml$', f)])
        actual_notes = len([f for f in self.files if re.match(r'ppt/notesSlides/notesSlide\d+\.xml$', f)])

        if declared_slides != actual_slides:
            self.issues.append({
                'type': 'APP_SLIDE_COUNT_MISMATCH',
                'msg': f"app.xml declares {declared_slides} slides but found {actual_slides}",
                'declared': declared_slides,
                'actual': actual_slides
            })

        if declared_notes != actual_notes:
            self.issues.append({
                'type': 'APP_NOTE_COUNT_MISMATCH',
                'msg': f"app.xml declares {declared_notes} notes but found {actual_notes}",
                'declared': declared_notes,
                'actual': actual_notes
            })

    def _validate_comments(self):
        """Validate comments and comment authors."""
        # Check for commentAuthors.xml
        if 'ppt/commentAuthors.xml' in self.files:
            try:
                root = ET.fromstring(self.files['ppt/commentAuthors.xml'])
            except ET.ParseError:
                self.issues.append({
                    'type': 'INVALID_COMMENT_AUTHORS_XML',
                    'msg': 'commentAuthors.xml is not valid XML'
                })
                return

            # Extract all author IDs
            ns = {'p': 'http://schemas.openxmlformats.org/presentationml/2006/main'}
            author_ids = set()
            for author in root.findall('.//p:cmAuthor', ns):
                author_id = author.get('id')
                if author_id:
                    author_ids.add(author_id)

            # Check comments for each slide
            for name in self.files:
                if not re.match(r'ppt/comments/comment\d+\.xml$', name):
                    continue

                try:
                    comment_root = ET.fromstring(self.files[name])
                except ET.ParseError:
                    self.issues.append({
                        'type': 'INVALID_COMMENT_XML',
                        'msg': f'Invalid XML in {name}'
                    })
                    continue

                # Check that all comment author IDs reference valid authors
                for comment in comment_root.findall('.//p:cm', ns):
                    author_id = comment.get('authorId')
                    if author_id and author_id not in author_ids:
                        self.issues.append({
                            'type': 'COMMENT_INVALID_AUTHOR_ID',
                            'msg': f"{name} references invalid authorId: {author_id}",
                            'file': name,
                            'author_id': author_id
                        })
                
    def get_presentation_data(self) -> Dict:
        """Extract presentation.xml key data."""
        data = {
            'slideIdList': [],
            'slideMasterIdList': [],
            'notesMasterIdList': [],
            'sections': []
        }
        if 'ppt/presentation.xml' not in self.files:
            return data

        try:
            root = ET.fromstring(self.files['ppt/presentation.xml'])
        except ET.ParseError:
            return data

        # slideIdList
        for sldId in root.findall('.//p:sldIdLst/p:sldId', NS):
            data['slideIdList'].append({
                'id': sldId.get('id'),
                'rid': sldId.get('{%s}id' % NS['r'])
            })

        # slideMasterIdList
        for smId in root.findall('.//p:sldMasterIdLst/p:sldMasterId', NS):
            data['slideMasterIdList'].append({
                'id': smId.get('id'),
                'rid': smId.get('{%s}id' % NS['r'])
            })

        # notesMasterIdList
        for nmId in root.findall('.//p:notesMasterIdLst/p:notesMasterId', NS):
            data['notesMasterIdList'].append({
                'id': nmId.get('id'),
                'rid': nmId.get('{%s}id' % NS['r'])
            })

        # sections
        for sect in root.findall('.//p:sectionLst/p:section', NS):
            section_data = {
                'name': sect.get('name', ''),
                'id': sect.get('id', ''),
                'slideIds': []
            }
            for sldId in sect.findall('.//p:sldIdLst/p:sldId', NS):
                section_data['slideIds'].append(sldId.get('id'))
            data['sections'].append(section_data)

        return data

    def get_app_properties(self) -> Dict:
        """Extract docProps/app.xml counts."""
        data = {'slides': 0, 'notes': 0, 'hidden_slides': 0}
        if 'docProps/app.xml' not in self.files:
            return data

        try:
            root = ET.fromstring(self.files['docProps/app.xml'])
        except ET.ParseError:
            return data

        ns = {'ep': 'http://schemas.openxmlformats.org/officeDocument/2006/extended-properties'}

        slides = root.find('.//ep:Slides', ns)
        notes = root.find('.//ep:Notes', ns)
        hidden = root.find('.//ep:HiddenSlides', ns)

        data['slides'] = int(slides.text) if slides is not None and slides.text else 0
        data['notes'] = int(notes.text) if notes is not None and notes.text else 0
        data['hidden_slides'] = int(hidden.text) if hidden is not None and hidden.text else 0

        return data

    def get_noteslides_data(self) -> Dict[str, Dict]:
        """Extract all notesSlides references."""
        notes_data = {}
        for name in self.files:
            if not re.match(r'ppt/notesSlides/notesSlide\d+\.xml$', name):
                continue

            try:
                root = ET.fromstring(self.files[name])
            except ET.ParseError:
                continue

            rels = self.relations.get(name, [])
            slide_rel = next((r for r in rels if 'slide' in r['type'] and r['mode'] != 'External'), None)
            master_rel = next((r for r in rels if 'notesMaster' in r['type']), None)

            notes_data[name] = {
                'slide_target': slide_rel['resolved'] if slide_rel else None,
                'master_target': master_rel['resolved'] if master_rel else None
            }

        return notes_data

    def get_comments_data(self) -> Dict:
        """Extract comments and authors data."""
        data = {'authors': [], 'comments': {}}

        # Extract authors
        if 'ppt/commentAuthors.xml' in self.files:
            try:
                root = ET.fromstring(self.files['ppt/commentAuthors.xml'])
                ns = {'p': 'http://schemas.openxmlformats.org/presentationml/2006/main'}
                for author in root.findall('.//p:cmAuthor', ns):
                    data['authors'].append({
                        'id': author.get('id'),
                        'name': author.get('name'),
                        'initials': author.get('initials')
                    })
            except ET.ParseError:
                pass

        # Extract comments for each slide
        for name in self.files:
            if not re.match(r'ppt/comments/comment\d+\.xml$', name):
                continue

            try:
                root = ET.fromstring(self.files[name])
                ns = {'p': 'http://schemas.openxmlformats.org/presentationml/2006/main'}
                comments = []
                for comment in root.findall('.//p:cm', ns):
                    comments.append({
                        'authorId': comment.get('authorId'),
                        'dt': comment.get('dt'),
                        'idx': comment.get('idx')
                    })
                data['comments'][name] = comments
            except ET.ParseError:
                pass

        return data
        
    def get_hash_map(self) -> Dict[str, List[str]]:
        """Get hash -> [filenames] mapping for duplicate detection."""
        hash_map = defaultdict(list)
        for name, h in self.hashes.items():
            hash_map[h].append(name)
        return dict(hash_map)


class PPTXComparator:
    """Compare two PPTX files."""
    
    def __init__(self, corrupt: PPTXAnalyzer, repaired: PPTXAnalyzer):
        self.corrupt = corrupt
        self.repaired = repaired
        self.diffs: List[Dict] = []
        
    def compare(self):
        """Run full comparison."""
        self._compare_file_lists()
        self._compare_content_types()
        self._compare_relations()
        self._compare_presentation_xml()
        self._compare_sections()
        self._compare_noteslides()
        self._compare_app_properties()
        self._compare_comments()
        self._compare_xml_content()
        self._detect_hash_matches()
        return self
        
    def _compare_file_lists(self):
        """Compare file presence."""
        c_files = set(self.corrupt.files.keys())
        r_files = set(self.repaired.files.keys())
        
        only_corrupt = c_files - r_files
        only_repaired = r_files - c_files
        
        for f in only_corrupt:
            self.diffs.append({
                'type': 'FILE_REMOVED',
                'severity': 'HIGH',
                'file': f,
                'msg': f"File in corrupt but removed by repair: {f}"
            })
            
        for f in only_repaired:
            self.diffs.append({
                'type': 'FILE_ADDED',
                'severity': 'MEDIUM',
                'file': f,
                'msg': f"File added by repair: {f}"
            })
            
    def _compare_content_types(self):
        """Compare [Content_Types].xml."""
        c_ct = self.corrupt.content_types
        r_ct = self.repaired.content_types
        
        for part, ctype in c_ct.items():
            if part not in r_ct:
                self.diffs.append({
                    'type': 'CONTENT_TYPE_REMOVED',
                    'severity': 'HIGH',
                    'part': part,
                    'msg': f"Content type override removed: {part}"
                })
            elif r_ct[part] != ctype:
                self.diffs.append({
                    'type': 'CONTENT_TYPE_CHANGED',
                    'severity': 'MEDIUM',
                    'part': part,
                    'corrupt': ctype,
                    'repaired': r_ct[part],
                    'msg': f"Content type changed: {part}"
                })
                
    def _compare_relations(self):
        """Compare all relations."""
        c_rels = self.corrupt.relations
        r_rels = self.repaired.relations
        
        all_sources = set(c_rels.keys()) | set(r_rels.keys())
        
        for source in all_sources:
            c_rel_map = {r['id']: r for r in c_rels.get(source, [])}
            r_rel_map = {r['id']: r for r in r_rels.get(source, [])}
            
            for rid, rel in c_rel_map.items():
                if rid not in r_rel_map:
                    self.diffs.append({
                        'type': 'RELATION_REMOVED',
                        'severity': 'HIGH',
                        'source': source,
                        'rid': rid,
                        'target': rel['target'],
                        'msg': f"Relation removed: {source} {rid} -> {rel['target']}"
                    })
                elif r_rel_map[rid]['target'] != rel['target']:
                    self.diffs.append({
                        'type': 'RELATION_TARGET_CHANGED',
                        'severity': 'HIGH',
                        'source': source,
                        'rid': rid,
                        'corrupt_target': rel['target'],
                        'repaired_target': r_rel_map[rid]['target'],
                        'msg': f"Relation target changed: {source} {rid}"
                    })
                    
    def _compare_presentation_xml(self):
        """Compare presentation.xml structures."""
        c_data = self.corrupt.get_presentation_data()
        r_data = self.repaired.get_presentation_data()

        c_slides = {s['rid']: s['id'] for s in c_data['slideIdList']}
        r_slides = {s['rid']: s['id'] for s in r_data['slideIdList']}

        if c_slides != r_slides:
            self.diffs.append({
                'type': 'SLIDE_ID_LIST_CHANGED',
                'severity': 'CRITICAL',
                'corrupt': c_slides,
                'repaired': r_slides,
                'msg': 'slideIdList differs between corrupt and repaired'
            })

        # Detailed presentation.xml.rels comparison
        self._compare_presentation_rels_detailed()

    def _compare_presentation_rels_detailed(self):
        """Compare presentation.xml.rels in detail - detect misplaced relations."""
        c_rels = self.corrupt.relations.get('ppt/presentation.xml', [])
        r_rels = self.repaired.relations.get('ppt/presentation.xml', [])

        # Group relations by type
        def group_by_type(rels):
            by_type = {}
            for rel in rels:
                rel_type = rel['type'].split('/')[-1]
                if rel_type not in by_type:
                    by_type[rel_type] = []
                by_type[rel_type].append(rel)
            return by_type

        c_by_type = group_by_type(c_rels)
        r_by_type = group_by_type(r_rels)

        all_types = set(c_by_type.keys()) | set(r_by_type.keys())

        for rel_type in all_types:
            c_count = len(c_by_type.get(rel_type, []))
            r_count = len(r_by_type.get(rel_type, []))

            if c_count != r_count:
                severity = 'CRITICAL' if rel_type in ('image', 'slideLayout', 'notesSlide') else 'HIGH'
                self.diffs.append({
                    'type': 'PRES_RELS_TYPE_COUNT_CHANGED',
                    'severity': severity,
                    'rel_type': rel_type,
                    'corrupt_count': c_count,
                    'repaired_count': r_count,
                    'msg': f"presentation.xml.rels {rel_type} count: {c_count} -> {r_count}",
                    'corrupt_targets': [r['target'] for r in c_by_type.get(rel_type, [])],
                    'repaired_targets': [r['target'] for r in r_by_type.get(rel_type, [])]
                })

        # Total relation count
        if len(c_rels) != len(r_rels):
            self.diffs.append({
                'type': 'PRES_RELS_TOTAL_COUNT_CHANGED',
                'severity': 'HIGH',
                'corrupt_count': len(c_rels),
                'repaired_count': len(r_rels),
                'msg': f"Total presentation.xml.rels count: {len(c_rels)} -> {len(r_rels)}"
            })

    def _compare_sections(self):
        """Compare sections between corrupt and repaired."""
        c_data = self.corrupt.get_presentation_data()
        r_data = self.repaired.get_presentation_data()

        c_sections = {s['name']: s for s in c_data['sections']}
        r_sections = {s['name']: s for s in r_data['sections']}

        # Check for removed sections
        for name in c_sections:
            if name not in r_sections:
                self.diffs.append({
                    'type': 'SECTION_REMOVED',
                    'severity': 'HIGH',
                    'section_name': name,
                    'msg': f"Section '{name}' was removed by repair"
                })

        # Check for added sections
        for name in r_sections:
            if name not in c_sections:
                self.diffs.append({
                    'type': 'SECTION_ADDED',
                    'severity': 'MEDIUM',
                    'section_name': name,
                    'msg': f"Section '{name}' was added by repair"
                })

        # Check for changed section slide references
        for name in c_sections:
            if name in r_sections:
                c_ids = set(c_sections[name]['slideIds'])
                r_ids = set(r_sections[name]['slideIds'])
                if c_ids != r_ids:
                    self.diffs.append({
                        'type': 'SECTION_SLIDES_CHANGED',
                        'severity': 'HIGH',
                        'section_name': name,
                        'corrupt_slides': list(c_ids),
                        'repaired_slides': list(r_ids),
                        'msg': f"Section '{name}' slide references changed"
                    })

    def _compare_noteslides(self):
        """Compare notesSlides references."""
        c_notes = self.corrupt.get_noteslides_data()
        r_notes = self.repaired.get_noteslides_data()

        c_note_names = set(c_notes.keys())
        r_note_names = set(r_notes.keys())

        # Check for removed notes
        for name in c_note_names - r_note_names:
            self.diffs.append({
                'type': 'NOTESLIDE_REMOVED',
                'severity': 'HIGH',
                'noteslide': name,
                'msg': f"NoteSlide removed by repair: {name}"
            })

        # Check for added notes
        for name in r_note_names - c_note_names:
            self.diffs.append({
                'type': 'NOTESLIDE_ADDED',
                'severity': 'MEDIUM',
                'noteslide': name,
                'msg': f"NoteSlide added by repair: {name}"
            })

        # Check for changed references
        for name in c_note_names & r_note_names:
            c_slide = c_notes[name]['slide_target']
            r_slide = r_notes[name]['slide_target']
            if c_slide != r_slide:
                self.diffs.append({
                    'type': 'NOTESLIDE_SLIDE_REF_CHANGED',
                    'severity': 'CRITICAL',
                    'noteslide': name,
                    'corrupt_slide': c_slide,
                    'repaired_slide': r_slide,
                    'msg': f"NoteSlide {name} slide reference changed: {c_slide} -> {r_slide}"
                })

            c_master = c_notes[name]['master_target']
            r_master = r_notes[name]['master_target']
            if c_master != r_master:
                self.diffs.append({
                    'type': 'NOTESLIDE_MASTER_REF_CHANGED',
                    'severity': 'HIGH',
                    'noteslide': name,
                    'corrupt_master': c_master,
                    'repaired_master': r_master,
                    'msg': f"NoteSlide {name} master reference changed"
                })

    def _compare_app_properties(self):
        """Compare docProps/app.xml properties."""
        c_props = self.corrupt.get_app_properties()
        r_props = self.repaired.get_app_properties()

        if c_props['slides'] != r_props['slides']:
            self.diffs.append({
                'type': 'APP_SLIDE_COUNT_CHANGED',
                'severity': 'HIGH',
                'corrupt': c_props['slides'],
                'repaired': r_props['slides'],
                'msg': f"app.xml slide count changed: {c_props['slides']} -> {r_props['slides']}"
            })

        if c_props['notes'] != r_props['notes']:
            self.diffs.append({
                'type': 'APP_NOTE_COUNT_CHANGED',
                'severity': 'HIGH',
                'corrupt': c_props['notes'],
                'repaired': r_props['notes'],
                'msg': f"app.xml note count changed: {c_props['notes']} -> {r_props['notes']}"
            })

        if c_props['hidden_slides'] != r_props['hidden_slides']:
            self.diffs.append({
                'type': 'APP_HIDDEN_SLIDE_COUNT_CHANGED',
                'severity': 'MEDIUM',
                'corrupt': c_props['hidden_slides'],
                'repaired': r_props['hidden_slides'],
                'msg': f"app.xml hidden slide count changed: {c_props['hidden_slides']} -> {r_props['hidden_slides']}"
            })

    def _compare_comments(self):
        """Compare comments and authors."""
        c_comments = self.corrupt.get_comments_data()
        r_comments = self.repaired.get_comments_data()

        # Compare authors
        c_author_ids = set(a['id'] for a in c_comments['authors'] if a['id'])
        r_author_ids = set(a['id'] for a in r_comments['authors'] if a['id'])

        if c_author_ids != r_author_ids:
            self.diffs.append({
                'type': 'COMMENT_AUTHORS_CHANGED',
                'severity': 'MEDIUM',
                'corrupt': list(c_author_ids),
                'repaired': list(r_author_ids),
                'msg': f"Comment authors changed: {len(c_author_ids)} -> {len(r_author_ids)}"
            })

        # Compare comments per slide
        c_comment_files = set(c_comments['comments'].keys())
        r_comment_files = set(r_comments['comments'].keys())

        for file in c_comment_files - r_comment_files:
            self.diffs.append({
                'type': 'COMMENT_FILE_REMOVED',
                'severity': 'MEDIUM',
                'file': file,
                'msg': f"Comment file removed by repair: {file}"
            })

        for file in r_comment_files - c_comment_files:
            self.diffs.append({
                'type': 'COMMENT_FILE_ADDED',
                'severity': 'LOW',
                'file': file,
                'msg': f"Comment file added by repair: {file}"
            })

        for file in c_comment_files & r_comment_files:
            c_count = len(c_comments['comments'][file])
            r_count = len(r_comments['comments'][file])
            if c_count != r_count:
                self.diffs.append({
                    'type': 'COMMENT_COUNT_CHANGED',
                    'severity': 'MEDIUM',
                    'file': file,
                    'corrupt': c_count,
                    'repaired': r_count,
                    'msg': f"Comment count in {file}: {c_count} -> {r_count}"
                })
            
    def _compare_xml_content(self):
        """Compare XML content for semantic differences."""
        common_files = set(self.corrupt.files.keys()) & set(self.repaired.files.keys())
        xml_files = [f for f in common_files if f.endswith('.xml')]

        for f in xml_files:
            c_hash = self.corrupt.hashes[f]
            r_hash = self.repaired.hashes[f]

            if c_hash != r_hash:
                # Perform detailed comparison for important files
                if re.match(r'ppt/slides/slide\d+\.xml$', f):
                    self._compare_slide_xml(f)
                elif re.match(r'ppt/slideMasters/slideMaster\d+\.xml$', f):
                    self._compare_slidemaster_xml(f)
                elif re.match(r'ppt/slideLayouts/slideLayout\d+\.xml$', f):
                    self._compare_slidelayout_xml(f)
                elif f == 'ppt/presentation.xml':
                    # Already compared in detail
                    pass
                else:
                    self.diffs.append({
                        'type': 'XML_CONTENT_CHANGED',
                        'severity': 'MEDIUM',
                        'file': f,
                        'corrupt_hash': c_hash,
                        'repaired_hash': r_hash,
                        'msg': f"XML content differs: {f}"
                    })

    def _compare_slide_xml(self, filename: str):
        """Compare slide XML in detail."""
        try:
            c_root = ET.fromstring(self.corrupt.files[filename])
            r_root = ET.fromstring(self.repaired.files[filename])
        except ET.ParseError:
            return

        # Compare clrMapOvr references (important for theme/master links)
        c_clr = c_root.find('.//{%s}clrMapOvr' % NS['p'])
        r_clr = r_root.find('.//{%s}clrMapOvr' % NS['p'])

        if c_clr is not None and r_clr is not None:
            c_rid = c_clr.get('{%s}id' % NS['r'])
            r_rid = r_clr.get('{%s}id' % NS['r'])
            if c_rid != r_rid:
                self.diffs.append({
                    'type': 'SLIDE_CLRMAPOVR_RID_CHANGED',
                    'severity': 'HIGH',
                    'file': filename,
                    'corrupt_rid': c_rid,
                    'repaired_rid': r_rid,
                    'msg': f"Slide {filename}: clrMapOvr rId changed from {c_rid} to {r_rid}"
                })

        # Count shape elements (high-level content comparison)
        c_shapes = len(c_root.findall('.//{%s}sp' % NS['p']))
        r_shapes = len(r_root.findall('.//{%s}sp' % NS['p']))
        if c_shapes != r_shapes:
            self.diffs.append({
                'type': 'SLIDE_SHAPE_COUNT_CHANGED',
                'severity': 'LOW',
                'file': filename,
                'corrupt': c_shapes,
                'repaired': r_shapes,
                'msg': f"Slide {filename}: shape count changed from {c_shapes} to {r_shapes}"
            })

    def _compare_slidemaster_xml(self, filename: str):
        """Compare slideMaster XML in detail."""
        try:
            c_root = ET.fromstring(self.corrupt.files[filename])
            r_root = ET.fromstring(self.repaired.files[filename])
        except ET.ParseError:
            return

        # Count slideLayout references
        c_layouts = len(c_root.findall('.//{%s}sldLayoutIdLst/{%s}sldLayoutId' % (NS['p'], NS['p'])))
        r_layouts = len(r_root.findall('.//{%s}sldLayoutIdLst/{%s}sldLayoutId' % (NS['p'], NS['p'])))

        if c_layouts != r_layouts:
            self.diffs.append({
                'type': 'SLIDEMASTER_LAYOUT_COUNT_CHANGED',
                'severity': 'HIGH',
                'file': filename,
                'corrupt': c_layouts,
                'repaired': r_layouts,
                'msg': f"SlideMaster {filename}: layout count changed from {c_layouts} to {r_layouts}"
            })

    def _compare_slidelayout_xml(self, filename: str):
        """Compare slideLayout XML in detail."""
        try:
            c_root = ET.fromstring(self.corrupt.files[filename])
            r_root = ET.fromstring(self.repaired.files[filename])
        except ET.ParseError:
            return

        # Compare type attribute
        c_type = c_root.get('type', '')
        r_type = r_root.get('type', '')

        if c_type != r_type:
            self.diffs.append({
                'type': 'SLIDELAYOUT_TYPE_CHANGED',
                'severity': 'HIGH',
                'file': filename,
                'corrupt': c_type,
                'repaired': r_type,
                'msg': f"SlideLayout {filename}: type changed from '{c_type}' to '{r_type}'"
            })
                
    def _detect_hash_matches(self):
        """Find files with same content but different names."""
        c_hashes = self.corrupt.get_hash_map()
        r_hashes = self.repaired.get_hash_map()
        
        for h, c_files in c_hashes.items():
            if h in r_hashes:
                r_files = r_hashes[h]
                if set(c_files) != set(r_files):
                    self.diffs.append({
                        'type': 'RENAMED_IDENTICAL_CONTENT',
                        'severity': 'INFO',
                        'hash': h,
                        'corrupt_files': c_files,
                        'repaired_files': r_files,
                        'msg': f"Same content, different names: {c_files} vs {r_files}"
                    })
                    
    def generate_report(self) -> str:
        """Generate human-readable report."""
        lines = []
        lines.append("=" * 70)
        lines.append("PPTX DEEP COMPARISON REPORT")
        lines.append("=" * 70)
        lines.append(f"\nCorrupt:  {self.corrupt.path}")
        lines.append(f"Repaired: {self.repaired.path}")
        lines.append(f"\nCorrupt files:  {len(self.corrupt.files)}")
        lines.append(f"Repaired files: {len(self.repaired.files)}")
        
        # Issues in corrupt file
        lines.append("\n" + "=" * 70)
        lines.append("ISSUES IN CORRUPT FILE")
        lines.append("=" * 70)
        for issue in self.corrupt.issues:
            lines.append(f"  [{issue['type']}] {issue['msg']}")
        if not self.corrupt.issues:
            lines.append("  (none detected)")
            
        # Grouped diffs by severity
        lines.append("\n" + "=" * 70)
        lines.append("DIFFERENCES (CRITICAL)")
        lines.append("=" * 70)
        critical = [d for d in self.diffs if d.get('severity') == 'CRITICAL']
        for d in critical:
            lines.append(f"  [{d['type']}] {d['msg']}")
        if not critical:
            lines.append("  (none)")
            
        lines.append("\n" + "=" * 70)
        lines.append("DIFFERENCES (HIGH)")
        lines.append("=" * 70)
        high = [d for d in self.diffs if d.get('severity') == 'HIGH']
        for d in high:
            lines.append(f"  [{d['type']}] {d['msg']}")
        if not high:
            lines.append("  (none)")
            
        lines.append("\n" + "=" * 70)
        lines.append("FILES REMOVED BY REPAIR")
        lines.append("=" * 70)
        removed = [d for d in self.diffs if d['type'] == 'FILE_REMOVED']
        for d in removed:
            lines.append(f"  - {d['file']}")
        if not removed:
            lines.append("  (none)")
            
        # Section differences
        lines.append("\n" + "=" * 70)
        lines.append("SECTION DIFFERENCES")
        lines.append("=" * 70)
        section_diffs = [d for d in self.diffs if 'SECTION' in d['type']]
        for d in section_diffs:
            lines.append(f"  [{d['type']}] {d['msg']}")
        if not section_diffs:
            lines.append("  (none)")

        # NoteSlide differences
        lines.append("\n" + "=" * 70)
        lines.append("NOTESLIDE DIFFERENCES")
        lines.append("=" * 70)
        noteslide_diffs = [d for d in self.diffs if 'NOTESLIDE' in d['type']]
        for d in noteslide_diffs:
            lines.append(f"  [{d['type']}] {d['msg']}")
        if not noteslide_diffs:
            lines.append("  (none)")

        # App properties differences
        lines.append("\n" + "=" * 70)
        lines.append("APP PROPERTIES DIFFERENCES")
        lines.append("=" * 70)
        app_diffs = [d for d in self.diffs if 'APP_' in d['type']]
        for d in app_diffs:
            lines.append(f"  [{d['type']}] {d['msg']}")
        if not app_diffs:
            lines.append("  (none)")

        # Comment differences
        lines.append("\n" + "=" * 70)
        lines.append("COMMENT DIFFERENCES")
        lines.append("=" * 70)
        comment_diffs = [d for d in self.diffs if 'COMMENT' in d['type']]
        for d in comment_diffs:
            lines.append(f"  [{d['type']}] {d['msg']}")
        if not comment_diffs:
            lines.append("  (none)")

        # Presentation.xml.rels differences (NEW SECTION)
        lines.append("\n" + "=" * 70)
        lines.append("PRESENTATION.XML.RELS ANALYSIS")
        lines.append("=" * 70)
        pres_rels_diffs = [d for d in self.diffs if 'PRES_RELS' in d['type']]
        pres_rels_issues = [i for i in self.corrupt.issues if 'PRES_RELS' in i['type'] or i['type'] == 'INVALID_PRESENTATION_RELS']

        if pres_rels_issues:
            lines.append("  ISSUES IN CORRUPT FILE:")
            for issue in pres_rels_issues:
                lines.append(f"    [{issue['type']}] {issue['msg']}")
                if 'invalid_relations' in issue:
                    for inv_rel in issue['invalid_relations'][:10]:
                        lines.append(f"      - {inv_rel['rid']}: {inv_rel['type']} -> {inv_rel['target']}")
                        lines.append(f"        Reason: {inv_rel['reason']}")
                    if len(issue['invalid_relations']) > 10:
                        lines.append(f"      ... and {len(issue['invalid_relations']) - 10} more")

        if pres_rels_diffs:
            lines.append("\n  DIFFERENCES VS REPAIRED:")
            for d in pres_rels_diffs:
                lines.append(f"    [{d['type']}] {d['msg']}")
                if 'corrupt_targets' in d and d['corrupt_targets']:
                    lines.append(f"      Corrupt targets: {d['corrupt_targets'][:5]}{'...' if len(d['corrupt_targets']) > 5 else ''}")
                if 'repaired_targets' in d and d['repaired_targets']:
                    lines.append(f"      Repaired targets: {d['repaired_targets'][:5]}{'...' if len(d['repaired_targets']) > 5 else ''}")

        if not pres_rels_issues and not pres_rels_diffs:
            lines.append("  (no issues detected)")

        lines.append("\n" + "=" * 70)
        lines.append("ROOT CAUSE HYPOTHESES")
        lines.append("=" * 70)
        
        hypotheses = self._generate_hypotheses()
        for i, h in enumerate(hypotheses, 1):
            lines.append(f"\n{i}. {h['title']}")
            lines.append(f"   Evidence: {h['evidence']}")
            lines.append(f"   Fix: {h['fix']}")
            
        return '\n'.join(lines)
        
    def _generate_hypotheses(self) -> List[Dict]:
        """Generate root cause hypotheses based on diffs."""
        hypotheses = []
        
        # Check for duplicate rId (CRITICAL)
        dup_rids = [i for i in self.corrupt.issues if i['type'] == 'DUPLICATE_RID']
        if dup_rids:
            hypotheses.append({
                'title': 'CRITICAL: Duplicate rId in .rels files',
                'evidence': f"{len(dup_rids)} duplicate rId found: {[i['rid'] for i in dup_rids[:3]]}",
                'fix': 'Remap rId when merging to avoid collisions (use incremental counter per .rels file)'
            })
        
        # Check for duplicate slideId/masterId (CRITICAL)
        dup_ids = [i for i in self.corrupt.issues if i['type'] in ('DUPLICATE_SLIDE_ID', 'DUPLICATE_MASTER_ID')]
        if dup_ids:
            hypotheses.append({
                'title': 'CRITICAL: Duplicate slideId/slideMasterId in presentation.xml',
                'evidence': f"{len(dup_ids)} duplicate ID issues",
                'fix': 'Generate unique IDs when merging (use max existing ID + offset)'
            })
        
        # Check for layout without master
        no_master = [i for i in self.corrupt.issues if i['type'] == 'LAYOUT_NO_MASTER']
        if no_master:
            hypotheses.append({
                'title': 'SlideLayout missing slideMaster relation',
                'evidence': f"{len(no_master)} layouts without master: {[i['part'] for i in no_master[:3]]}",
                'fix': 'Ensure slideLayout .rels always includes relation to its slideMaster'
            })
        
        # Check for orphan files
        orphans = [d for d in self.diffs if d['type'] == 'FILE_REMOVED']
        if orphans:
            hypotheses.append({
                'title': 'Orphan Parts (unreferenced files)',
                'evidence': f"{len(orphans)} files removed: {[d['file'] for d in orphans[:5]]}",
                'fix': 'After merge, scan all files and remove any not referenced in .rels'
            })
            
        # Check for broken relations
        broken = [i for i in self.corrupt.issues if i['type'] == 'BROKEN_REF']
        if broken:
            hypotheses.append({
                'title': 'Broken Relations (rId points to non-existent part)',
                'evidence': f"{len(broken)} broken refs detected",
                'fix': 'After adding parts, validate all relations resolve to existing files'
            })
            
        # Check for relation changes
        rel_changes = [d for d in self.diffs if 'RELATION' in d['type']]
        if rel_changes:
            hypotheses.append({
                'title': 'Invalid Relations Structure',
                'evidence': f"{len(rel_changes)} relation differences",
                'fix': 'Ensure rId remapping is consistent across all XML files'
            })
            
        # Check for slideIdList issues
        slide_issues = [d for d in self.diffs if d['type'] == 'SLIDE_ID_LIST_CHANGED']
        if slide_issues:
            hypotheses.append({
                'title': 'Inconsistent slideIdList',
                'evidence': 'slideIdList in presentation.xml differs',
                'fix': 'Ensure slideId values are unique and rId references match .rels'
            })

        # Check for NoteSlide issues
        noteslide_issues = [i for i in self.corrupt.issues if 'NOTESLIDE' in i['type']]
        noteslide_diffs = [d for d in self.diffs if 'NOTESLIDE' in d['type'] and d.get('severity') in ('CRITICAL', 'HIGH')]
        if noteslide_issues or noteslide_diffs:
            hypotheses.append({
                'title': 'NoteSlide Reference Issues',
                'evidence': f"{len(noteslide_issues)} issues in corrupt, {len(noteslide_diffs)} differences with repaired",
                'fix': 'Ensure bidirectional Slide <-> NoteSlide references are updated when cloning/renaming'
            })

        # Check for Section issues
        section_issues = [i for i in self.corrupt.issues if 'SECTION' in i['type']]
        section_diffs = [d for d in self.diffs if 'SECTION' in d['type'] and d.get('severity') in ('CRITICAL', 'HIGH')]
        if section_issues or section_diffs:
            hypotheses.append({
                'title': 'Section Reference Issues',
                'evidence': f"{len(section_issues)} issues in corrupt, {len(section_diffs)} differences with repaired",
                'fix': 'Update section slideId references after merge when slide IDs are renumbered'
            })

        # Check for App properties mismatch
        app_issues = [i for i in self.corrupt.issues if 'APP_' in i['type']]
        app_diffs = [d for d in self.diffs if 'APP_' in d['type'] and d.get('severity') in ('CRITICAL', 'HIGH')]
        if app_issues or app_diffs:
            hypotheses.append({
                'title': 'App Properties Count Mismatch',
                'evidence': f"{len(app_issues)} issues in corrupt, {len(app_diffs)} differences with repaired",
                'fix': 'Update docProps/app.xml slide and note counts after merge'
            })

        # Check for notesMaster issues
        notesmaster_issues = [i for i in self.corrupt.issues if 'NOTESMASTER' in i['type']]
        if notesmaster_issues:
            hypotheses.append({
                'title': 'NotesMaster Missing or Invalid',
                'evidence': f"{len(notesmaster_issues)} notesMaster issues detected",
                'fix': 'Ensure notesMaster is properly referenced in presentation.xml and all notesSlides'
            })

        # Check for INVALID PRESENTATION.XML.RELS (CRITICAL)
        invalid_pres_rels = [i for i in self.corrupt.issues if 'PRES_RELS_INVALID' in i['type'] or i['type'] == 'INVALID_PRESENTATION_RELS']
        pres_rels_diffs = [d for d in self.diffs if 'PRES_RELS_TYPE_COUNT' in d['type'] and d.get('severity') == 'CRITICAL']
        if invalid_pres_rels or pres_rels_diffs:
            # Gather details about what types are misplaced
            misplaced_types = set()
            for issue in invalid_pres_rels:
                if 'invalid_relations' in issue:
                    for rel in issue['invalid_relations']:
                        misplaced_types.add(rel['type'])
            for d in pres_rels_diffs:
                if d['corrupt_count'] > d['repaired_count']:
                    misplaced_types.add(d['rel_type'])

            hypotheses.insert(0, {  # Insert at beginning (critical)
                'title': 'CRITICAL: Misplaced relations in presentation.xml.rels',
                'evidence': f"Found {len(invalid_pres_rels)} invalid relation types: {list(misplaced_types)}. "
                           f"These relations should be in their respective container .rels files, not presentation.xml.rels.",
                'fix': 'Fix the PHP merge code: when cloning resources, ensure relations are added to the correct .rels file:\n'
                       '   - Images -> slide/layout/master .rels\n'
                       '   - SlideLayouts -> slideMaster .rels\n'
                       '   - NotesSlides -> slide .rels\n'
                       '   - Check setResource() and addRelation() methods'
            })

        if not hypotheses:
            hypotheses.append({
                'title': 'No obvious issues detected',
                'evidence': 'Differences may be formatting or optional elements',
                'fix': 'Check XML content diffs in detail'
            })
            
        return hypotheses


def main():
    if len(sys.argv) < 3:
        print(f"Usage: {sys.argv[0]} <corrupt.pptx> <repaired.pptx>")
        sys.exit(1)
        
    corrupt_path = sys.argv[1]
    repaired_path = sys.argv[2]
    
    print("Analyzing corrupt file...")
    corrupt = PPTXAnalyzer(corrupt_path, "CORRUPT").analyze()
    
    print("Analyzing repaired file...")
    repaired = PPTXAnalyzer(repaired_path, "REPAIRED").analyze()
    
    print("Comparing...")
    comparator = PPTXComparator(corrupt, repaired)
    comparator.compare()
    
    report = comparator.generate_report()
    print(report)
    
    # Export JSON for detailed analysis
    json_report = {
        'corrupt_issues': corrupt.issues,
        'repaired_issues': repaired.issues,
        'diffs': comparator.diffs
    }
    
    json_path = 'pptx_compare_report.json'
    with open(json_path, 'w') as f:
        json.dump(json_report, f, indent=2)
    print(f"\nDetailed JSON report: {json_path}")


if __name__ == '__main__':
    main()
